<?php

namespace Tools;

use Backend\Core\Engine\Base\ActionEdit as BackendBaseActionEdit;
use Backend\Modules\Blog\Engine\Model;
use Backend\Core\Engine\Model as BackendModel;
use Backend\Core\Engine\Language as BL;
use Frontend\Init;

require __DIR__ . '/autoload.php';
require __DIR__ . '/app/AppKernel.php';
require __DIR__ . '/app/KernelLoader.php';

/**
 * Class ImportFlux
 *
 * This script will import blog posts without comments from a flux cms site.
 *
 * @author Patrick Jezek <patrick@jezek.ch>
 */
class ImportFlux extends BackendBaseActionEdit
{
    public $site = 'my.flux.cms';

    public $blogQuery = "select id, post_author, post_date, post_content, post_title, post_uri, changed, post_info FROM blogposts ORDER BY post_date ASC";

    public $blog2CategoryQuery = "SELECT bc.name FROM blogposts2categories AS b2c, blogcategories AS bc WHERE b2c.blogcategories_id = bc.id AND b2c.blogposts_id = ";

    public $tagsQuery = "SELECT t.tag FROM properties2tags AS p2t, tags AS t WHERE p2t.tag_id = t.id AND p2t.path = ";

    protected $targetFolder = 'src/Frontend/Files/userfiles/images/blog';

    public $config = array(
        'db' => array(
            'host' => 'HOST',
            'user' => 'USER',
            'password' => 'PASSWORD',
            'database' => 'DATABASE',
        ),
    );

    public function __construct($config = array())
    {
        if ($config) {
            $this->config = $config;
        }
    }

    public $mysqli = null;

    public function getMysqli()
    {
        if (!$this->mysqli) {
            $this->mysqli = new \mysqli(
                $this->config['db']['host'],
                $this->config['db']['user'],
                $this->config['db']['password'],
                $this->config['db']['database']
            );
        }

        return $this->mysqli;
    }

    /**
     * Handle the category of a post
     *
     * We'll check if the category exists in the fork blog module, and create it if it does not.
     *
     * @param string $category The post category
     *
     * @return int
     */
    private function handleCategory($category = '')
    {
        // Does a category with this name exist?
        /* @var \SpoonDatabase $db */
        $db = BackendModel::getContainer()->get('database');
        $id = (int)$db->getVar(
            'SELECT id FROM blog_categories WHERE title=? AND language=?',
            array($category, BL::getWorkingLanguage())
        );

        // We found an id!
        if ($id > 0) {
            return $id;
        }

        // Return default if we got an empty string
        if (trim($category) == '') {
            return 2;
        }

        // We should create a new category
        $cat = array();
        $cat['language'] = BL::getWorkingLanguage();
        $cat['title'] = $category;
        $meta = array();
        $meta['keywords'] = $category;
        $meta['description'] = $category;
        $meta['title'] = $category;
        $meta['url'] = $category;

        return Model::insertCategory($cat, $meta);
    }

    private function categoriesAndTags2NewTags($list)
    {
        $newTags = array();
        $mapping = array(
            // categories: 'All' and 'general' are not handeld.
            'Moblog Pictures' => array('moblog'),
            'Serverli' => array('server'),
            'Australien' => array('Australia'),
            'Familie' => array('family'),
            'my3Dwork' => array('3D'),
            'windows' => array('Windows'),
            'OSX' => array('Apple', 'OSX'),
            'Unity3d' => array('Unity3D'),
            'JavaScript' => array('JavaScript'),
            // tags
            'netra' => array('server', 'netra'),
            'sun' => array('server', 'Sun'),
            'server' => array('server'),
            'Familie' => array('family'),
            'Australien' => array('Australia'),
            'webtuesday' => array('webtuesday'),
            'xsi' => array('3D', 'XSI'),
            'realflow' => array('3D', 'Realflow'),
            'wobnini' => array('wobini'),
            'wobini' => array('wobini'),
            'mac' => array('Apple', 'OSX'),
            'mini' => array('Apple', 'OSX'),
            'osx' => array('Apple', 'OSX'),
            'php' => array('PHP'),
            'xen' => array('XEN', 'server'),
            'serverli' => array('server'),
            'shellscript' => array('shell scripting'),
            'bash' => array('shell scripting'),
            'bash' => array('shell scripting'),
            'fish' => array('game development', 'daddelbox GmbH'),
        );

        foreach ($list as $oldName) {
            if (array_key_exists($oldName, $mapping)) {
                $newTags = array_merge($mapping[$oldName], $newTags);
            }
        }

        return array_unique($newTags);
    }

    private function getCategoriesForBlogPost($id)
    {
        $categories = array();

        $mysqli = $this->getMysqli();
        if ($result = $mysqli->query($this->blog2CategoryQuery . $id)) {
            while ($row = mysqli_fetch_assoc($result)) {
                if (trim($row['name']) != '') {
                    $categories[] = $row['name'];
                }
            }
        }

        return $categories;
    }

    private function getTagsFor($url)
    {
        $tags = array();

        $mysqli = $this->getMysqli();
        if ($result = $mysqli->query($this->tagsQuery . '"/blog/' . $url . '.html"')) {
            while ($row = mysqli_fetch_assoc($result)) {
                if (trim($row['tag']) != '') {
                    $tags[] = $row['tag'];
                }
            }
        }

        return $tags;
    }

    /**
     * Helper to upload pictures when they where located on the old blog.
     *
     * @param $url
     *
     * @return string
     */
    public function uploadImage($url)
    {

        // fix some wrong encodings
        $url = str_replace(' ', '%20', trim($url));

        $fromBlog = false;

        // check if url is from blog, so it starts with:
        // http(s)://my.flux.xms or /
        $pattern = '/^http(s)*\:\/\/' . $this->site . '/';
        preg_match($pattern, $url, $matches);
        if (!empty($matches[0])) {
            $fromBlog = true;
        } else {
            preg_match('/^\//', $url, $matches);
            if (!empty($matches[0])) {
                $fromBlog = true;
                $url = 'http://' . $this->site . $url;
            }
        }

        if (!$fromBlog) {
            return $url;
        }

        // check if image contains: ../dynimages/NNN/..
        $isDynImage = false;
        $pattern = '/\/dynimages\/(\d{1,4})\//';
        preg_match($pattern, $url, $matches);
        if (!empty($matches[0])) {
            $isDynImage = true;
            $url = preg_replace($pattern, '/', $url);
        }

        // fetch image and store it with a thumbnail
        $file = file_get_contents($url);
        $fileName = sha1($file);
        $size = getimagesize($url);
        $extension = image_type_to_extension($size[2]);
        file_put_contents($this->targetFolder . '/source/' . $fileName . $extension, $file);
        if (in_array($extension, array('.jpeg', '.png'))) {
            $thumbnail = new \SpoonThumbnail(
                $this->targetFolder . '/source/' . $fileName . $extension,
                320
            );
            $thumbnail->setAllowEnlargement(true);
            $thumbnail->parseToFile($this->targetFolder . '/320x/' . $fileName . $extension);
        } else {
            error_log("failed to create thumbnail for: " . $url . ", ext: " . $extension);
        }

        return '/' . $this->targetFolder . (($isDynImage) ? '/320x/' : '/source/') . $fileName . $extension;
    }

    /**
     * Helper to upload and create thumbnails on images from a blog post.
     *
     * @param string $body
     *
     * @return string
     */
    public function fixImages($body)
    {
        if (empty($body)) {
            return "";
        }

        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $body);
        $doc->encoding = 'UTF-8';

        $xpath = new \DOMXPath($doc);
        $res = $xpath->query('//a');

        // check for //a/img
        for ($i = 0; $i < $res->length; $i++) {
            $node = $res->item($i);
            $link = $node->getAttribute('href');
            if ($link) {
                // fetch it's image
                $imgs = $node->getElementsByTagName('img');
                for ($j = 0; $j < $imgs->length; $j++) {
                    $img = $imgs->item($j);
                    // those images should be small
                    $img->setAttribute('src', $this->uploadImage($img->getAttribute('src')));
                    $img->setAttribute('data', 'processed');
                }
                if ($imgs->length === 0) {
                    // TODO: create lightbox if it points to an image
                }
                // this image should be lightboxed
                $node->setAttribute('href', $this->uploadImage($link));
            }
        }

        // check for //img only
        $res = $xpath->query('//img');
        for ($i = 0; $i < $res->length; $i++) {
            $img = $res->item($i);
            if ($img->getAttribute('data') === 'processed') {
                $img->removeAttribute('data');
            } else {
                $img->setAttribute('src', $this->uploadImage($img->getAttribute('src')));
            }
        }

        return str_replace(
            array(
                '<body>',
                '</body>'
            ),
            '',
            $doc->saveXML($doc->getElementsByTagName('body')->item(0))
        );
    }

    public function process()
    {

        $mysqli = $this->getMysqli();
        if ($result = $mysqli->query($this->blogQuery)) {
            printf("Select returned %d blog entries.\n", $result->num_rows);
            while ($row = mysqli_fetch_assoc($result)) {

                $item = array();
                $item['user_id'] = 1; // $row['post_author']
                $item['status'] = 'active';
                $item['allow_comments'] = 'N';
                $item['title'] = $row['post_title'];
                $item['text'] = $this->fixImages($row['post_content']);
                // TODO: geotags (solved with JavaScript for now)
                $item['text'] .= $row['post_info'];
                $item['created_on'] = $row['post_date'];
                $item['publish_on'] = $row['post_date'];
                $item['edited_on'] = $row['changed'];

                $item['category_id'] = 1; // Default

                $tags = $this->categoriesAndTags2NewTags(
                    array_merge($this->getCategoriesForBlogPost($row['id']), $this->getTagsFor($row['post_uri']))
                );

                $meta = array(
                    'url' => $row['post_uri'],
                );

                // TODO: comments
                $comments = array();
//        $comment['comment_id'] = '1';
//        $comment['comment_author'] = '';
//        $comment['comment_author_email'] = '';
//        $comment['comment_author_url'] = '';
//        $comment['comment_author_IP'] = '';
//        $comment['comment_date'] = '';
//        $comment['comment_date_gmt'] = '0000-00-00 00:00:00';
//        $comment['comment_content'] = '';
//        $comment['comment_approved'] = '1';
//        $comment['comment_type'] = 'comment';
//        $comment['comment_parent'] = '0';
//        $comment['comment_user_id'] = '0';
//        $comment['created_on'] = '';
//        $comment['status'] = 'published';
//        $comments[0] = $comment;

                $revision = Model::insertCompletePost($item, $meta, $tags, $comments);
                error_log("processed blog: " . $row['id']);
            }
        }
    }

}

// bootstrap fork cms
if (!defined('APPLICATION')) {
    define('APPLICATION', 'Frontend');
}
if (!defined('NAMED_APPLICATION')) {
    define('NAMED_APPLICATION', APPLICATION);
}
$kernel = new \AppKernel();
$loader = new Init($kernel);
BL::setWorkingLanguage('en');
$loader->initialize(APPLICATION);
$loader->passContainerToModels();

$importer = new ImportFlux();
$result = $importer->process();
