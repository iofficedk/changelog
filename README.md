# Changelog
A small script to do the boring job making a changelog. 
It extracts data from Git log and put nicely into a changelog.{branch}.md file in doc root. 

Satisfies most bosses.

```php
$Changelog = \Io\Changelog\App::get()->create();
```

Or

```php
$Changelog = \Io\Changelog\App::get();
$Changelog->create();
```
# Options 
set the output file {name}.md, default is changelog.{current branch}.md 
```php
$Changelog->setName({name})
```

set how long back the changelog must go. Max 12 month back min 1 month back
```php
$Changelog->setSince({number})
```
