<?php
/**
 * Falang K2 search plugin
 * Date: 21.03.13
 *
 * @package Falang
 * @copyright (C) 2013 Oļegs Čapligins
 * @license GNU General Public License v3
 */

// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

jimport('joomla.plugin.plugin');

class plgSearchFalangK2 extends JPlugin
{

    /**
     * @return array An array of search areas
     */
    function onContentSearchAreas()
    {
        JPlugin::loadLanguage('plg_search_k2', JPATH_ADMINISTRATOR);
        static $areas = array(
            'k2' => 'K2_ITEMS'
        );
        return $areas;
    }

    /**
     * Main search function
     *
     * @param string $text
     * @param string $phrase
     * @param string $ordering
     * @param array|null $areas
     * @return array
     */
    function onContentSearch($text, $phrase='', $ordering='', $areas=null)
    {
        require_once JPATH_SITE.DS.'components'.DS.'com_content'.DS.'helpers'.DS.'route.php';
        require_once JPATH_SITE.DS.'administrator'.DS.'components'.DS.'com_search'.DS.'helpers'.DS.'search.php';

        JPlugin::loadLanguage('plg_search_k2', JPATH_ADMINISTRATOR);

        $rows 	    = array();
        $db		    = JFactory::getDBO();
        $user	    = JFactory::getUser();
        $groups	    = implode(',', $user->getAuthorisedViewLevels());
        $lang 	    = JFactory::getLanguage()->getTag();
        $text       = trim( $text );
        $limit 		= $this->params->def( 'search_limit', 		 50);
        $activeLang = $this->params->def( 'active_language_only', 1);
        $nullDate 	= $db->getNullDate();
        $date 		= JFactory::getDate();
        $now  		= $date->toMySQL();

        // If there are defined several search areas
        if (is_array( $areas )) {
            if (!array_intersect( $areas, array_keys( $this->onContentSearchAreas() ) )) {
                return array();
            }
        }

        // An empty string in search term?
        if ($text == '') {
            return array();
        }

        // WHERE critera depending on search mode
        $wheres = array();
        switch ($phrase) {
            case 'exact':
                $text  = $db->Quote( '%'.$db->getEscaped( $text, true ).'%', false );
                $where = "LOWER(fc.value) LIKE ".$text;
                break;

            case 'all':
            case 'any':
            default:
                $words = explode( ' ', $text );
                $wheres = array();
                foreach ($words as $word) {
                    $word	  = $db->Quote( '%'.$db->getEscaped( $word, true ).'%', false );
                    $wheres[] = "LOWER(fc.value) LIKE ".$word;
                }
                $where = '(' . implode( ($phrase == 'all' ? ') AND (' : ') OR ('), $wheres ) . ')';
                break;
        }

        // Sorting
        $morder = '';
        switch ($ordering) {
            case 'oldest':
                $order = 'a.created ASC';
                break;

            case 'popular':
                $order = 'a.hits DESC';
                break;

            case 'alpha':
                $order = 'a.title ASC';
                break;

            case 'category':
                $order  = 'b.title ASC, a.title ASC';
                $morder = 'a.title ASC';
                break;

            case 'newest':
            default:
                $order = 'a.created DESC';
                break;
        }

        // Search
        if ( $limit > 0 ) {

            $query = "
				SELECT
				a.id as contid,
				a.catid as catid,
                		IF (fka.value = null, fc.value, fka.value) AS title,
				a.created AS created,
				a.introtext, a.fulltext,
				CASE WHEN CHAR_LENGTH(a.alias) THEN CONCAT_WS(':', a.id, a.alias) ELSE a.id END as slug,
				CASE WHEN CHAR_LENGTH(b.alias) THEN CONCAT_WS(':', b.id, b.alias) ELSE b.id END as catslug,
				'2' AS browsernav,
				l.lang_code as jflang, l.title_native as jflname,
				fc.value as translated,
				IF( fk.value IS NULL OR fk.value='', b.name, fk.value ) AS cattitle

					FROM #__k2_items AS a
					INNER JOIN #__k2_categories AS b ON b.id=a.catid
					LEFT JOIN #__falang_content as fc ON fc.reference_id = a.id
					LEFT JOIN #__languages as l ON fc.language_id = l.lang_id
					LEFT JOIN #__falang_content AS fk ON ( fk.reference_id = a.catid AND fk.reference_table = 'k2_categories' )
			                LEFT JOIN #__falang_content AS fka ON (fka.reference_id = fc.reference_id AND fka.reference_field = 'title')

						WHERE ( ".$where." )
						AND a.published = 1
						AND b.published = 1
						AND a.access IN (".$groups.")
						AND b.access IN (".$groups.")
						AND ( a.publish_up = ".$db->Quote($nullDate)." OR a.publish_up <= ".$db->Quote($now)." )
						AND ( a.publish_down = ".$db->Quote($nullDate)." OR a.publish_down >= ".$db->Quote($now)." )
						AND fc.reference_table = 'k2_items'
						".( $activeLang ? "\n AND l.lang_code = '$lang'" : '')."
						GROUP BY a.id
						ORDER BY $order
			";

            $db->setQuery( $query, 0, $limit );
            $list   = $db->loadObjectList();
            $limit -= count($list);

            if(isset($list)) {
                foreach($list as $key => $item) {
                    $list[$key]->section = $item->sectitle."/".$item->cattitle." - ".$item->jflname;
                    $list[$key]->text    = $item->translated ;
                    $list[$key]->href    = JRoute::_(K2HelperRoute::getItemRoute($item->slug, $item->catslug));
                }
            }

            $rows[] = $list;

        }

        // Resultset
        $results = array();
        if(count($rows)) {
            foreach($rows as $row) {
                $results = array_merge($results, (array) $row);
            }
        }

        return $results;

    }

}
