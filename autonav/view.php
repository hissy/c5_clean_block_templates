<?php
//注意: 次のページ属性を追加することで、拡張機能が利用できます（管理画面 → ページ＆テーマ → 属性）。
//
// 1) ハンドル: replace_link_with_first_in_nav
//    タイプ: 真偽値
//    機能: ページがチェックされているとき、ナビゲーションメニューでこのページがクリックされたとき、このページの最初の子ページ（サブページ）に代わりに移動する。
//
// 2) ハンドル: exclude_subpages_from_nav
//    タイプ: 真偽値
//    機能: ページがチェックされているとき、すべての子ページ（サブページ）はナビゲーションメニューから除かれる（このページ自身は含まれる）。
//
// 3) ハンドル:disable_link_in_nav
//    タイプ: 真偽値
//    機能: ページがチェックされているとき、ナビゲーションメニューに現れるが、クリックできない（どのページにもリンクされない）。
//
// 4) ハンドル: nav_item_class
//    タイプ: テキスト
//    機能: テキストボックスに入力された内容が追加のCSSクラスとしてこのページのナビゲーション項目に出力される（注意: この機能を動作させるには、下記のCSSセクションのこのコード "$ni->attrClass" をコメントから外すこと）。



/*************************************************
 * デザイナーへ: スクロールダウンしてください！（この部分は無視）
 *************************************************/
defined('C5_EXECUTE') or die("Access Denied.");

$c = Page::getCurrentPage();

//Create an array of parent cIDs so we can determine the "nav path" of the current page
$inspectC = $c;
$selectedPathCIDs = array($inspectC->getCollectionID());
$parentCIDnotZero=true;
while ($parentCIDnotZero) {
	$cParentID = $inspectC->cParentID;
	if (!intval($cParentID)) {
		$parentCIDnotZero=false;
	} else {
		if ($cParentID != HOME_CID) {
			$selectedPathCIDs[] = $cParentID; //Don't want home page in nav-path-selected
		}
		$inspectC = Page::getById($cParentID);
	}
}

//Remove excluded pages from the list (do this first because some of the data prep code needs to "look ahead" in the list)
$allNavItems = $controller->generateNav();
$includedNavItems = array();
$excluded_parent_level = 9999; //Arbitrarily high number denotes that we're NOT currently excluding a parent (because all actual page levels will be lower than this)
$exclude_children_below_level = 9999; //Same deal as above. Note that in this case "below" means a HIGHER number (because a lower number indicates higher placement in the sitemp -- e.g. 0 is top-level)
foreach ($allNavItems as $ni) {
	$_c = $ni->getCollectionObject();
	$current_level = $ni->getLevel();
	
	if ($_c->getAttribute('exclude_nav') && ($current_level <= $excluded_parent_level)) {
		$excluded_parent_level = $current_level;
		$exclude_page = true;
	} else if (($current_level > $excluded_parent_level) || ($current_level > $exclude_children_below_level)) {
		$exclude_page = true;
	} else {
		$excluded_parent_level = 9999; //Reset to arbitrarily high number to denote that we're no longer excluding a parent
		$exclude_children_below_level = $_c->getAttribute('exclude_subpages_from_nav') ? $current_level : 9999;
		$exclude_page = false;
	}
	
	if (!$exclude_page) {
		$includedNavItems[] = $ni;
	}
}

//Prep all data and put it into a clean structure so markup output is as simple as possible
$navItems = array();
$navItemCount = count($includedNavItems);
for ($i = 0; $i < $navItemCount; $i++) {
	$ni = $includedNavItems[$i];
	$_c = $ni->getCollectionObject();
	$current_level = $ni->getLevel();
	
	//Link target (e.g. open in new window)
	$target = $ni->getTarget();
	$target = empty($target) ? '_self' : $target;
	
	//Link URL
	$pageLink = false;
	if ($_c->getAttribute('replace_link_with_first_in_nav')) {
		$subPage = $_c->getFirstChild(); //Note: could be a rare bug here if first child was excluded, but this is so unlikely (and can be solved by moving it in the sitemap) that it's not worth the trouble to check
		if ($subPage instanceof Page) {
			$pageLink = Loader::helper('navigation')->getLinkToCollection($subPage); //We could optimize by instantiating the navigation helper outside the loop, but this is such an infrequent attribute that I prefer code clarity over performance in this case
		}
	}
	if (!$pageLink) {
		$pageLink = $ni->getURL();
	}
	
	//Link Disabled attribute (do this separately from the page link, in case the url is needed for something else -- e.g. javascript)
	$disableLink = $_c->getAttribute('disable_link_in_nav');
	
	//Current/ancestor page
	$selected = false;
	$path_selected = false;
	if ($c->getCollectionID() == $_c->getCollectionID()) {
		$selected = true; //Current item is the page being viewed
		$path_selected = true;
	} elseif (in_array($_c->getCollectionID(), $selectedPathCIDs)) {
		$path_selected = true; //Current item is an ancestor of the page being viewed
	}
	
	//Calculate difference between this item's level and next item's level so we know how many closing tags to output in the markup
	$next_level = isset($includedNavItems[$i+1]) ? $includedNavItems[$i+1]->getLevel() : 0;
	$levels_between_this_and_next = $current_level - $next_level;
	
	//Determine if this item has children (can't rely on $ni->hasChildren() because it doesn't ignore excluded items!)
	$has_children = $next_level > $current_level;
	
	//Calculate if this is the first item in its level (useful for CSS classes)
	$prev_level = isset($includedNavItems[$i-1]) ? $includedNavItems[$i-1]->getLevel() : -1;
	$is_first_in_level = $current_level > $prev_level;
	
	//Calculate if this is the last item in its level (useful for CSS classes)
	$is_last_in_level = true;
	for ($j = $i+1; $j < $navItemCount; $j++) {
		if ($includedNavItems[$j]->getLevel() == $current_level) {
			//we found a subsequent item at this level (before this level "ended"), so this is NOT the last in its level
			$is_last_in_level = false;
			break;
		}
		if ($includedNavItems[$j]->getLevel() < $current_level) {
			//we found a previous level before any other items in this level, so this IS the last in its level
			$is_last_in_level = true;
			break;
		}
	} //If loop ends before one of the "if" conditions is hit, then this is the last in its level (and $is_last_in_level stays true)
	
	//Custom CSS class
	$attribute_class = $_c->getAttribute('nav_item_class');
	$attribute_class = empty($attribute_class) ? '' : $attribute_class;
	
	//Page ID stuff
	$item_cid = $_c->getCollectionID();
	$is_home_page = ($item_cid == HOME_CID);
	
	
	//Package up all the data
	$navItem = new stdClass();
	$navItem->url = $pageLink;
	$navItem->name = $ni->getName();
	$navItem->target = $target;
	$navItem->level = $current_level + 1; //make this 1-based instead of 0-based (more human-friendly)
	$navItem->subDepth = $levels_between_this_and_next;
	$navItem->hasSubmenu = $has_children;
	$navItem->isFirst = $is_first_in_level;
	$navItem->isLast = $is_last_in_level;
	$navItem->isCurrent = $selected;
	$navItem->inPath = $path_selected;
	$navItem->attrClass = $attribute_class;
	$navItem->isEnabled = !$disableLink;
	$navItem->isHome = $is_home_page;
	$navItem->cID = $item_cid;
	$navItem->cObj = $_c;
	$navItems[] = $navItem;
}

/******************************************************************************
* デザイナーへ: CSSクラスのカスタマイズをここで行なってください。
*/
foreach ($navItems as $ni) {
	$classes = array();
	
	if ($ni->isCurrent) {
		//現在見ているページのクラス
		$classes[] = 'nav-selected';
	}
	
	if ($ni->inPath) {
		//現在見ているページの親ページのクラス
		$classes[] = 'nav-path-selected';
	}
	
	/*
	if ($ni->isFirst) {
		//すべてのメニューセクションの最初の項目のクラス（トップレベルの最初の項目と、すべてのドロップダウン・サブメニューの最初の項目）
		$classes[] = 'nav-first';
	}
	*/
	
	/*
	if ($ni->isLast) {
		//すべてのメニューセクションの最後の項目のクラス（トップレベルの最後の項目と、すべてのドロップダウン・サブメニューの最後の項目）
		$classes[] = 'nav-last';
	}
	*/
	
	/*
	if ($ni->hasSubmenu) {
		//ドロップダウン・サブメニューを持つ項目のクラス
		$classes[] = 'nav-dropdown';
	}
	*/
	
	/*
	if (!empty($ni->attrClass)) {
		//カスタムページ属性「nav_item_class」によってエンドユーザーから設定されるクラス
		$classes[] = $ni->attrClass;
	}
	*/
	
	/*
	if ($ni->isHome) {
		//ホームページ
		$classes[] = 'nav-home';
	}
	*/
	
	/*
	//それぞれのメニュー項目にユニークなクラスをつける
	$classes[] = 'nav-item-' . $ni->cID;
	*/
	
	//すべてのクラスをスペース区切りの文字列につなげる
	$ni->classes = implode(" ", $classes);
}

/******************************************************************************
* デザイナーへ: HTMLのカスタマイズをここで行なってください。
*/

echo '<ul class="nav">'; //トップレベルメニューの開始

foreach ($navItems as $ni) {
	
	echo '<li class="' . $ni->classes . '">'; //ナビゲーション項目の開始
	
	if ($ni->isEnabled) {
		echo '<a href="' . $ni->url . '" target="' . $ni->target . '" class="' . $ni->classes . '">' . $ni->name . '</a>';
	} else {
		echo '<span class="' . $ni->classes . '">' . $ni->name . '</span>';
	}
	
	if ($ni->hasSubmenu) {
		echo '<ul>'; //ドロップダウン・サブメニューの開始
	} else {
		echo '</li>'; //ナビゲーション項目の終了
		echo str_repeat('</ul></li>', $ni->subDepth); //ドロップダウン・サブメニューと、それらのトップレベルメニュー項目の終了
	}
}

echo '</ul>'; //トップレベルメニューの終了
