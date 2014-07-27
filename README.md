# flux2fork

Imports blog posts from [fluxcms](https://fosswiki.liip.ch/display/FLX/Home) to [forkcms](https://github.com/forkcms/forkcms).

## Requirements

- access to both mysql databases
- access via http(s) to your existing
- mysqli

## HowTo

Download import_flux.php to the root folder of your forkcms installation.

In ```ImportFlux``` change ```ImportFlux::site``` to mach your existing fluxcms url. This url will be used to download iamges.
Then configure your mysql settings to access your fluxcms in ```ImportFlux::config```.

Then change the mapping from fluxcms categories and tags to multiple forkcms tags in ```ImportFlux::categoriesAndTags2NewTags```

The user doing the posts is hardcoded in line 319.
The forkcms category is hardcoded in line 330.
Check also the lines between 319 - 330 for some other default values.

Then start the import and get some coffee:
```
php ./import_flux.php
```

Enjoy your migrated blog with forkcms.

Then please remove the script again.

# Known issues

- This script does not handle comments.
- Categories from flux cms will not be imported, but can be converted to tags.
- Plazes from moblog are preserved but not recognized by forkcms by default. This can be solved with some JavaScript.

## Notes

I will no longer use this script, but it took some time to complete.
