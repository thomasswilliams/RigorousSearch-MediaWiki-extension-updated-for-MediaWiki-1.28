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


if( !defined( 'MEDIAWIKI' ) ) {
    die();
}

require_once "SpecialPage.php";


$wgExtensionFunctions[] = "wfRigorousSearch";

$wgExtensionCredits['specialpage'][] = array(
    'path' => __FILE__,
    'name' =>        'RigorousSearch',
    'author' =>      'Johan the Ghost',
    'url' =>         'https://www.mediawiki.org/wiki/Extension:RigorousSearch',
    'version'=>      '1.0.1',
    'description' => 'Performs a search on full page text (including links etc.)',
);


function wfRigorousSearch() {
    global $wgMessageCache;
    $wgMessageCache->addMessages( array(
         'rigoroussearch' => 'Rigorous search'
    ));
    SpecialPage::addPage( new SpecialRigorousSearch );
}


class SpecialRigorousSearch extends SpecialPage {

    /*
     * Construct the extension and install it as a special page.
     */
    function SpecialRigorousSearch() {
        // Restrict access to users with "patrol" user rights.
        SpecialPage::SpecialPage('RigorousSearch', 'patrol');
    }


    /*
     * The special page handler function.  Receives the parameter
     * specified after "/", if any.
     */
    function execute($param) {
        global $wgRequest, $wgOut;
        global $wgUser;

        // This function is so slow that we only let users with
        // "patrol" user rights do it.
        if (!$wgUser->isAllowed('patrol')) {
            $wgOut->permissionRequired('patrol');
            return;
        }

        // What are we searching for?
        $pattern = null;
        if ($s = $wgRequest->getVal('pattern'))
            $pattern = $s;
        else if ($param)
            $pattern = $param;

        // What namespaces are we searching?  If none are specified (eg.
        // this is the first invocation), then default to all.
        $spaces = SearchEngine::searchableNamespaces();
        $searchNs = $this->selectedNamespaces($wgRequest, $spaces);
        if (!$searchNs)
            $searchNs = $spaces;

        // Set up the output.
        $this->setHeaders();
        $wgOut->setPagetitle(wfMsg('rigoroussearch'));

        // If we have a search term, do the search and show the results.
        if ($pattern)
            $wgOut->addWikiText($this->searchResults($pattern, $searchNs));

        // Make the search form and output it (as HTML, otherwise the
        // form tags get suppressed).
        $wgOut->addHTML($this->searchForm($pattern, $spaces, $searchNs));
    }


    /*
     * Extract the selected namespaces settings from the request object,
     * returning a list of index numbers to search.  We are given the
     * page request and the list of all searchable namespaces.
     * Returns the namespace list pruned to just the selected ones,
     * or null if none are selected.
     */
    function selectedNamespaces(&$request, &$spaces) {
        $arr = array();
        foreach ($spaces as $ns => $name) {
            if ($request->getCheck('ns' . $ns))
                $arr[$ns] = $name;
        }

        return count($arr) > 0 ? $arr : null;
    }


    /*
     * Perform a search for the given pattern, and return wiki markup
     * describing the results.
     *     $pattern          the pattern to search for
     *     $spaces           the list of namespaces selected for searching
     */
    function searchResults($pattern, &$spaces) {
        $db = &wfGetDB(DB_SLAVE);
        $out = '';

        // Confirm what we're searching for.
        // NOTE: we have to be careful abou the nowiki tag; using it
        // in the normal way will break the code page in mediawiki.org.
        $out .= "<div id=\"contentSub\">You rigorously searched for" .
                " '''<code><" . "nowiki>" . htmlspecialchars($pattern) .
                "<" . "/nowiki></code>'''</div>\n";

        // Perform the search, and get the match count and results list.
        $matches = 0;
        foreach ($spaces as $ns => $nsname) {
            $hits = $this->doSearch($db, $ns, $nsname, $pattern);
            $count = count($hits);

            // Output the results for this namespace, if any.
            if ($count != 0) {
                // Output the namespace header.
                if ($ns == 0)
                    $head = "Article Namespace";
                else
                    $head = $nsname . " Namespace";
                $out .= "<big>'''" . $head . ":'''</big> ";
                $out .= $this->matchCount($count) . ".\n";

                // Output the hit list.
                foreach ($hits as $hit)
                    $out .= "* [[" . $hit . "]]\n";

                $out .= "\n\n----\n";
                $matches += $count;
            }
        }

        // If we got no hits at all, say so.
        if ($matches == 0) {
            $out .= $this->matchCount($matches) . ".\n";
            $out .= "\n\n----\n";
        }

        // Let's not bother with the TOC.
        $out .= "__NOTOC__\n";

        return $out;
    }


    /*
     * Perform a search for the given pattern in a specified namespace.
     *     $db           Database handle
     *     $ns           Namespace ID to search
     *     $nsname       Name of the namespace (null for Main)
     *     $pattern      Pattern to search for
     *
     * Returns a list of the page titles which match.
     */
    function doSearch(&$db, $ns, $nsname, $pattern) {
        $matchingPages = array();

        // Escape the pattern string.  escapeLike does normal MySQL escaping,
        // plus additional processing necessary for LIKE.
        $pattern = $db->escapeLike($pattern);

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

            // Now select the text for the revision, if it matches the pattern.
            $queryTxt = "SELECT old_text FROM " . $db->tableName('text') .
                                " WHERE old_id = " . $text_id .
                                " AND  old_text LIKE '%" . $pattern . "%'";
            $textResult = $db->query($queryTxt);
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

            $db->freeResult($textResult);
        }

        $db->freeResult($pageResult);

        return $matchingPages;
    }


    /*
     * Create and return the HTML markup for the search form.
     *     $pattern          the default value for the pattern field
     *     $nameSpaces       the list of searchable namespaces
     *     $searchSpaces     the list of namespaces currently selected
     */
    function searchForm($pattern, &$nameSpaces, &$searchSpaces) {
        $out = '';

        // The form header, which links back to this page.
        $title = Title::makeTitle(NS_SPECIAL,'RigorousSearch');
        $action = $title->escapeLocalURL();
        $out .= "<form method=\"get\" action=\"$action\">\n";

        // The search text field.
        $pattern = htmlspecialchars($pattern);
        $out .= "<p>Search for <input type=\"text\" name=\"pattern\"" .
                " value=\"$pattern\" size=\"36\" />\n";

        // The search button.
        $out .= "<input type=\"submit\" name=\"searchx\" value=\"Search\" /></p>\n";

        // The table of namespace checkboxes.
        $out .= "<p><table><tr>\n";
        $i = 0;
        foreach ($nameSpaces as $ns => $name) {
            if ($i > 0 && $i % 8 == 0)
                $out .= "</tr><tr>\n";
            $checked = array_key_exists($ns, $searchSpaces) ? ' checked="checked"' : '';
            if (!$name)
                $name = "Main";
            else
                $name = str_replace('_', ' ', $name);
            $out .= "<td><label><input type='checkbox' value=\"1\" name=\"" .
              "ns{$ns}\"{$checked} />{$name}</label></td>\n";
            ++$i;
        }
        $out .= "</tr></table></p>\n";

        $out .= "</form>\n";

        return $out;
    }


    /*
     * Make a message describing a match count.
     */
    function matchCount($num) {
        if ($num == 0)
            return "No matches";
        return $num . ($num == 1 ? " match" : " matches");
    }

}