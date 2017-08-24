<?php
#
# SpecialRigorousSearch MediaWiki extension
#
# by Johan the Ghost 1 Feb 2007
#
# Copyright (C) 2007 Johan the Ghost
#
# What it is
# ==========
#
# This extension implements a full-page search facility, by the tedious
# method of individually searching the source of each page as stored in
# the "page" / "text" tables -- *not* the FULLTEXT index kept in the
# "searchindex" table for MySQL searches.
#
# This is VERY slow, and almost totally useless -- except that it allows
# searching of the complete page source, not just the user-visible version
# of the text stored in "searchindex".  So, for example, if you want to
# search for hyperlinks to a particular web site, this will work, whereas
# a MediaWiki search would not ("searchindex" includes link text, but not
# the link URL).  You can also use it to search for particular markup tags.
#
# A useful application is to search for novice users making "http://" links
# into the wiki instead of using regular wikilinks, which causes pages to
# appear orphaned when they're not.
#
# Usage
# =====
#
# The extension creates a new special page, Special:RigorousSearch.
# Because it uses a lot of resources, access is restricted to users with
# "patrol" user rights.  (You can change this easily enough; search for
# "patrol" below.)
#
# You can invoke this feature in multiple ways:
#
#   * Go to [[Special:RigorousSearch]], and fill in the search form.
#
#   * Link to [[Special:RigorousSearch/mypattern]] to do an immediate
#     search for "mypattern".  Due to URL processing, this won't work
#     for patterns containing special characters, including multiple
#     slashes (as in "http://...").
#
#   * Link to
#       [http://x/w/index.php?title=Special:RigorousSearch&pattern=mypattern]
#     This also does an immediate search for "mypattern", but you can use
#     "%2F" escapes for slashes, etc.
#
# Note that this is really slow.  You should only use it when necessary,
# and you probably shouldn't use it on large wikis at all.
#
# History
# =======
# 2007-05-02: 1.0.1 by Bananeweizen, made compatible with MediaWiki 1.7.x
#
# ############################################################################
#
# This program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License along
# with this program; if not, write to the Free Software Foundation, Inc.,
# 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
# http://www.gnu.org/copyleft/gpl.html

# ############################################################################
# Updated June 2017 by Thomas Williams https://thomasswilliams.github.io/
# to work with MediaWiki 1.28:
#  • added "extension.json", "en.json" files as needed to comply with new extension syntax
#  • kept original GNU license
#  • kept original comments and code where possible
#  • removed namespace selection - now only searches main "Article" namespace
#  • now always outputs search form
#  • fixed database access, was failing on deprecated function "escapeLike"
#  • removed deprecated MediaWiki globals $wgRequest, $wgOut, $wgUser
#  • removed check for "patrol" users
#  • minor changes to output text
if ( !defined( "MEDIAWIKI" ) ) {
	exit;
}

class SpecialRigorousSearch extends SpecialPage {

    /*
     * Constructor as per https://www.mediawiki.org/wiki/Manual:Special_pages
     */
    public function __construct( $name = '', $restriction = '', $listed = true ) {
      parent::__construct( 'RigorousSearch', 'search', $listed );
    }

    /*
     * The special page handler function.  Receives the parameter
     * specified after "/", if any.
     */
    public function execute( $par ) {
        $output = $this->getOutput();
        $request = $this->getRequest();

        // What are we searching for?
        $pattern = null;
        if ($s = $request->getText('pattern'))
            $pattern = $s;
        else if ($par)
            $pattern = $par;

        // Set up the output.
        $this->setHeaders();

        // Make the search form and output it (as HTML, otherwise the
        // form tags get suppressed).
        // always output the search form
        $output->addHTML($this->searchForm($pattern));

        // If we have a search term, do the search and show the results.
        if ($pattern)
            $output->addWikiText($this->searchResults($pattern));

    }

    /*
     * Perform a search for the given pattern, and return wiki markup
     * describing the results.
     *     $pattern          the pattern to search for
     */
    private function searchResults($pattern) {

        $out = '';

        // Perform the search, and get the match count and results list.
        $hits = $this->doSearch(0, null, $pattern);
        $count = count($hits);

        // Confirm what we're searching for.
        // NOTE: we have to be careful abou the nowiki tag; using it
        // in the normal way will break the code page in mediawiki.org.
        $out .= "<p>Rigorous Search for database text like " .
                " '''<code><" . "nowiki>" . htmlspecialchars($pattern) .
                "<" . "/nowiki></code>''' returned " .
                $this->matchCount($count) . "</p>\n";

        // if we got matches, Output the results
        if ($count != 0) {

            // Output the hit list.
            foreach ($hits as $hit)
                // hit list is returned as page titles, so wrap in interwiki links
                $out .= "[[" . $hit . "]]<br>\n";

            $out .= "\n\n";
        }

        // Let's not bother with the TOC.
        $out .= "__NOTOC__\n";

        return $out;
    }

    /*
     * Perform a search for the given pattern in a specified namespace.
     *     $ns           Namespace ID to search
     *     $nsname       Name of the namespace (null for Main)
     *     $pattern      Pattern to search for
     *
     * Returns a list of the page titles which match.
     */
    private function doSearch($ns, $nsname, $pattern) {
        // declare array of page titles that match the passed pattern
        $matchingPages = array();

        // remove wildcard, un-needed characters from passed pattern
        $pattern = str_replace( '"', '', $pattern );
        $pattern = str_replace( '*', '', $pattern );
        $pattern = str_replace( '%', '', $pattern );
        $pattern = str_replace( '\\', '', $pattern );
        $pattern = str_replace( '/', '', $pattern );
        $pattern = str_replace( '(', '', $pattern );
        $pattern = str_replace( ')', '', $pattern );
        $pattern = str_replace( '[', '', $pattern );
        $pattern = str_replace( ']', '', $pattern );
        $pattern = str_replace( '-', '', $pattern );
        $pattern = str_replace( '_', '', $pattern );
        $pattern = str_replace( '—', '', $pattern );
        $pattern = str_replace( '.', '', $pattern );
        $pattern = str_replace( ';', '', $pattern );
        $pattern = str_replace( ':', '', $pattern );

        // if no text to search for, return empty array and leave
        if ( $pattern == '' ) {
          return array(0, null);
        }

        // make pattern lowercase
        $pattern = strtolower($pattern);

        // connect to database, read-only query
        $db = wfGetDB(DB_SLAVE);

        // Select every page in the given namespace.  If we fail, return an
        // empty result.
        $pageCond = array('page_namespace' => $ns);
        $pageResult = $db->select('page', '*', $pageCond);
        if (!$pageResult)
            return array(0, null);

        // Process each page we found.
        while ($pageRow = $db->fetchObject($pageResult)) {
            // Now select the revision data for the page's latest rev.
            // If we can't, pass on this page.
            $revCond = array('rev_id' => $pageRow->page_latest);
            $revRow = $db->selectRow('revision', 'rev_text_id', $revCond);
            if (!$revRow)
                continue;
            $text_id = $revRow->rev_text_id;

            // create LIKE clause to match "old_text" column with passed pattern
            // prepend and append wildcard characters
            $like_clause = 'CONVERT (old_text using utf8) ' . $db->buildLike( $db->anyString(), $pattern, $db->anyString() );

            // query the database
            $textResult = $db->select(
              $db->tableName('text'), // table name
              array('old_text'), // columns of table to return (note column data is not needed though)
              array('old_id = ' . $text_id, $like_clause), // conditions AKA parameters
              __METHOD__ // function name, will appear in SQL logs as "SpecialRigorousSearch"
            );

            if (!$textResult)
                continue;

            // If it matches, list and count it.
            if ($db->numRows($textResult) > 0) {
                // Get the page title.
                $title = Title::makeTitle($ns, $pageRow->page_title);
                $link = $title->getFullText();

                // Add to the results.
                $matchingPages[] = $link;
            }
            // clean up
            $db->freeResult($textResult);
        }
        // clean up
        $db->freeResult($pageResult);

        return $matchingPages;
    }

    /*
     * Create and return the HTML markup for the search form.
     *     $pattern          the default value for the pattern field
     */
    private function searchForm($pattern) {
        $out = '';

        $out .= "<p>Rigorous search performs a database text search for your search term (multiple search terms are not supported). Database text searches may be slow on large wikis.</p>";
        // open HTML form
        $out .= "<form method=\"get\" action=\"/index.php\">\n";

        // The search text field.
        $pattern = htmlspecialchars($pattern);
        $out .= "<p><input type=\"search\" name=\"pattern\" value=\"$pattern\" size=\"36\" autofocus=\"autofocus\" autocomplete=\"off\">\n";
        // hidden output to return to this page on submit
        $out .= "<input type=\"hidden\" value=\"Special:RigorousSearch\" name=\"title\">";

        // The search button.
        $out .= "<input type=\"submit\" value=\"Rigorous Search\" tabindex=\"0\" role=\"button\"></p>\n";
        // close the form
        $out .= "</form>\n";

        return $out;
    }

    /*
     * Make a message describing a match count.
     */
    private function matchCount($num) {
        if ($num == 0)
            return "no matches - maybe try a different spelling or less characters.";
        return $num . ($num == 1 ? " match:" : " matches:");
    }
}