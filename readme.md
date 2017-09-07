# RigorousSearch MediaWiki extension updated for MediaWiki 1.28
A MediaWiki extension that searches the MySQL database underlying a wiki. Updated from [Johan The Ghost's](https://www.mediawiki.org/wiki/User:JohanTheGhost) original at <https://www.mediawiki.org/wiki/Extension:RigorousSearch>.

## Usage
This extension creates a new special page, ```Special:RigorousSearch```. Users can use this page to search the MySQL database for a single search word, and click links to wiki pages from the search results.

## Requirements
At the time of writing, this extension has been tested with MediaWiki 1.28, MySQL 5.5 and PHP 5.5 on Windows only.

## Installation
1. download the code from this repository:
    * *i18n*
       * *en.json*
     * *COPYING*
     * *extension.json*
     * *RigorousSearch_body.php*
2. create a new, empty folder __"SpecialRigorousSearch"__ in your wiki's "extensions" folder
3. save the downloaded code to the __"SpecialRigorousSearch"__ folder
4. add the following line to your LocalSettings.php file: 
  ````wfLoadExtension( 'RigorousSearch' );````
5. done - navigate to ```Special:Version``` on your wiki to verify the extension is successfully installed