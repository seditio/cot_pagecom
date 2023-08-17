<?php
/**
* Pagecom Plugin / Functions
*
* @package Pagecom
* @author Dmitri Beliavski
* @copyright (c) 2012-2023 seditio.by
*/

defined('COT_CODE') or die('Wrong URL');

/**
 * Returns condition as SQL string
 * @param		string	$cc_mode Selection mode: single, array, black or white
 * @param		string	$cc_cats Category (or categories in double quotes, comma separated)
 * @param		bool		$cc_subs Include subcategories
 * @return	string	Condition as SQL string
 */

if (!function_exists('cot_compilecats')) {
	function cot_compilecats($cc_mode, $cc_cats, $cc_subs) {

		if (!empty($cc_cats) && ($cc_mode == 'single' || $cc_mode == 'array' || $cc_mode == 'white' || $cc_mode == 'black')) {

			$cc_cats = str_replace(' ', '', $cc_cats);

			if ($cc_mode == 'single') {
				$cc_cats = cot_structure_children('page', $cc_cats, $cc_subs);
				$cc_where = ($cc_cats > 1) ? " AND page_cat IN ('" . implode("','", $cc_cats) . "')" : " AND page_cat = " . cot::$db->quote($cc_cats[0]);
			}
	    elseif ($cc_mode == 'array') {
	      $cc_cats = '"'.implode('","', $cc_cats).'"';
	      $cc_where = " AND page_cat IN ($cc_cats)";
	    }
			else {
				$what = ($cc_mode == 'black') ? 'NOT' : '';
				$cc_where = " AND page_cat ".$what." IN ($cc_cats)";
			}

		}
		else {
			$cc_where = '';
		}
		return $cc_where;
	}
}

/**
 * Generates comments widget
 * @param  string  $tpl        Template code
 * @param  integer $items      Number of items to show
 * @param  string  $order      Sorting order: 'date', 'views', 'rand'
 * @param  string  $blacklist  Category black list, semicolon separated
 * @param  string  $whitelist  Category white list, simicolon separated
 * @param  boolean $group      Group comments by page
 * @param  boolean $userid     Only by userid
 * @global CotDB   $db         Database connection
 * @return string              Parsed HTML
 */
function pagecom($tpl = 'pagecom', $items = 20, $order = 'date', $blacklist = '', $whitelist = '', $group = FALSE, $userid = '') {
	global $cfg, $db, $db_com, $db_pages, $db_users, $structure;

	// Compile lists
	if (!empty($blacklist))	{
		$bl = explode(';', $blacklist);
	}

	if (!empty($whitelist))	{
		$wl = explode(';', $whitelist);
	}

	// Get the cats
	$cats = array();
	if (!empty($blacklist) || !empty($whitelist))	{
		// All cats except bl/wl
		foreach ($structure['page'] as $code => $row) {
			if (cot_auth('page', $code, 'R') &&
				(!empty($blacklist) && !in_array($code, $bl) || !empty($whitelist) && in_array($code, $wl)))
			{
				$cats[] = $code;
			}
		}
	}
	if (count($cats) > 0 && count($cats) != count($structure['page'])) {
		$where_cat = "AND page_cat IN ('" . implode("','", $cats) . "')";
		$where_com_code = "AND com_code IN ('" . implode("','", $cats) . "')";
	}

	// Compile order
	if ($order == 'views') {
		$order_by = "page_count DESC, com_date DESC";
	}
	elseif ($order == 'rand') {
		$order_by = 'RAND()';
	}
	else {
		// Fallback is by date
		$order_by = "com_date DESC";
	}

	// Display the items
	$t = new XTemplate(cot_tplfile($tpl, 'plug'));

	$join_columns = '';
	$join_tables = '';

	/* === Hook === */
	foreach (cot_getextplugins('pagecom.query') as $pl)
	{
		include $pl;
	}
	/* ===== */

	if ($group) {
		$where_group = "AND c.com_id = (SELECT MAX(com_id) FROM $db_com AS c2 WHERE c2.com_code = c.com_code)";
	}

	if ($userid) {
		$where_userid = "AND com_authorid = $userid";
	}

	$query = "SELECT c.*, p.*, u.* $join_columns
		FROM $db_com AS c
			LEFT JOIN $db_pages AS p ON c.com_code = p.page_id
			LEFT JOIN $db_users AS u ON u.user_id = c.com_authorid
			$join_tables
		WHERE c.com_area = 'page' AND c.com_passed_mod = '1' AND ((p.page_state='0' $where_cat) OR (p.page_id IS NULL $where_com_code)) $where_group $where_userid
		ORDER BY $order_by LIMIT $items";

	$res = $db->query($query);

	$jj = 1;
	/* === Hook - Part 1 === */
	$loop_extplugins = cot_getextplugins('pagecom.loop');
	/* ===== */
	while ($row = $res->fetch())
	{
		if (empty($row['page_id']) && isset($structure['page'][$row['com_code']]))
		{
			// Category comments
			$cat = $structure['page'][$row['com_code']];
			$link_params = array('c' => $row['com_code']);
			$t->assign(array(
				'PAGECOM_ROW_PAGE_SHORTTITLE' => htmlspecialchars($cat['title']),
				'PAGECOM_ROW_PAGE_DESC'  => htmlspecialchars($cat['desc'])
			));
		}
		else
		{
			$t->assign(cot_generate_pagetags($row, 'PAGECOM_ROW_PAGE_'));
			$link_params = array('c' => $row['page_cat']);
			empty($row['page_alias']) ? $link_params['id'] = $row['page_id'] : $link_params['al'] = $row['page_alias'];
		}

		$t->assign(cot_generate_usertags($row, 'PAGECOM_ROW_AUTHOR_'));

		$com_text = cot_parse($row['com_text'], $cfg['plugin']['comments']['markup']);

		$t->assign(array(
			'PAGECOM_ROW_NUM' => $jj,
			'PAGECOM_ROW_ODDEVEN' => cot_build_oddeven($jj),
			'PAGECOM_ROW_ID' => $row['com_id'],
			'PAGECOM_ROW_URL' => cot_url('page', $link_params, '#c'.$row['com_id']),
			'PAGECOM_ROW_AUTHOR' => cot_build_user($row['com_authorid'], htmlspecialchars($row['com_author'])),
			'PAGECOM_ROW_AUTHORNAME' => htmlspecialchars($row['com_author']),
			'PAGECOM_ROW_AUTHORID' => $row['com_authorid'],
			'PAGECOM_ROW_TEXT' => $com_text,
			'PAGECOM_ROW_TEXT_PLAIN' => strip_tags($com_text),
			'PAGECOM_ROW_DATE' => cot_date('datetime_medium', $row['com_date']),
			'PAGECOM_ROW_DATE_STAMP' => $row['com_date'],
			'PAGECOM_ROW_CATTITLE' => htmlspecialchars($structure['page'][$row['page_cat']]['title'])
		));

		/* === Hook - Part 2 === */
		foreach ($loop_extplugins as $pl)
		{
			include $pl;
		}
		/* ===== */

		$t->parse('MAIN.PAGECOM_ROW');
		$jj++;
	}

	/* === Hook === */
	foreach (cot_getextplugins('pagecom.tags') as $pl)
	{
		include $pl;
	}
	/* ===== */

	$t->parse();
	return $t->text();
}
