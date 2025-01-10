<?php

/****************************************************************************************************
 *
 * fund.php 
 * 
 *    10/2023 revision for short-term use until direct BruKnow queries are available
 * 
 *    This page uses an input GET variable to works with fund.tpl to create a web page 
 *    with information pertaining to one endowment fund and associated purchased items
 *    of the Brown University Library. An electronic image of the bookplate appears; most physical 
 *    items do not have a physical bookplate.
 *
 *     
 *    The page will display titles of up to five items purchased using the fund; items are links 
 *    to their BruKnow record. 
 *    
 *    Items for each fund are now obtained from one source:  
 *       The MySQL table, bookplates.items_bruknow_comprehensive, which may be updated less frequently than BruKnow;
 *       all rows pertaining to the current fund's EN-number are sorted by date, DESC, and the first 
 *       5 records are displayed on this fund's page (up to 5, in cases where fewer than 5 exist.)  
 *
 *        Each title will contain a link to the title's BruKnow record, using MMSID as the identifier  *        in BruKnow.  
 *    
 *
 *    A link to a BruKnow page containing all results for the current EN-number will then be displayed *     
 *
 *
 ****************************************************************************************************/





require ('/var/www/common/guest_bookplates_iconnect.php');
mysqli_select_db($link,"bookplates");

require_once "HTML/Template/ITX.php";
$template = new HTML_Template_ITX('/var/www/html/bookplates/templates/');
$template->loadTemplatefile('fund.tpl', true, true);



/**********************************************
*
* Add standard BUL PL Header, in header.php,
* (w/standard menu items shared across BUL web)
* to a PHP Templates ITX variable. YF 8/26/24
*
***********************************************/

ob_start();
include('header.php');
$pl_menus = ob_get_contents();
ob_end_clean();

$template->setCurrentBlock('BUL_PL_HEADER');
$template->setVariable('PL_MENUS', $pl_menus);
$template->parseCurrentBlock();

/*********************************
* End of BUL PL Header
*********************************/



// filters incoming variable, account, for disallowed characters (security issue)

$string_vars = array('account');
foreach ($string_vars as $string_var){
	$test = isset($_REQUEST[$string_var]) ? $_REQUEST[$string_var] : '';
	${$string_var} = filter_var($test, FILTER_VALIDATE_REGEXP,  array("options"=>array("regexp"=>"/^[a-zA-Z0-9]*$/i")));
}

$testquery = "select name from endowments
WHERE account = \"$account\" and suppress != \"y\"
";
$testresult = mysqli_query($link,$testquery);

if (mysqli_num_rows($testresult) > 0) {
}
else {
	$account = "EN463494";  
}

// Query bookplates.endowments table for requested Fund's data
$query = "";
$query = "SELECT * FROM endowments
WHERE account = \"$account\" AND suppress != \"y\"
";
$result = mysqli_query($link,$query);
$row = mysqli_fetch_array($result);

$name = $row['name'];
$shortname = $row['shortname'];
$account = $row['account'];
$account_id = $row['account_id'];
$description = $row ['description'];
$image = $row ['image'];
$description = nl2br($description);


$template->setCurrentBlock('HEADER');
$template->setVariable('NAME', $name);
$template->parseCurrentBlock();

$template->setCurrentBlock('FUND');
$template->setVariable('IMAGE', $image);
$template->setVariable('IMAGE_NAME', $name);
$template->setVariable('DESCRIPTION', $description);
$template->parseCurrentBlock();

$template->setCurrentBlock('ITEM_INTRO');
if ($account == "EN460438" || $account == "EN464191") {
	$template->setVariable('INTRO', '<p>Materials purchased with this fund, or acquired by gift of the donor, include:</p><p>');
}
else {
	$template->setVariable('INTRO', '<p>Materials purchased with this fund include:</p><p>');
}
$template->parseCurrentBlock();

$primoKey = 'l8xxb6d67936651542c5bb27533681340a71';
$almaKey = 'l8xxc0c3ba775bc64de69bd985dc89bd86c6';
function convertToDate($dateString) {
	// provided a date if can't read one
	if (strlen($dateString) !== 6) {
    	$dateString = '210701';
	}

	$year = intval(substr($dateString, 0, 2));
	$month = intval(substr($dateString, 2, 2)) - 1;
	$day = intval(substr($dateString, 4, 2));

	$fullYear = $year < 30 ? $year + 2000 : $year + 1900;

	$date = DateTime::createFromFormat('Y-m-d', "$fullYear-$month-$day");
	return $date ? $date->format('Y-m-d') : '2021-07-01';
}

$primoSearchApi = "https://api-eu.hosted.exlibrisgroup.com/primo/v1/search?apikey=$primoKey&q=any,contains,$account&tab=Everything&scope=MyInst_and_CI&vid=01BU_INST:BROWN";
$almaBibApi = "https://api-eu.hosted.exlibrisgroup.com/almaws/v1/bibs?apikey=$almaKey";

$allResults = [];
$offset = 0;
$moreResults = true;

// the result is paginated by 10 so keep reading until finished
while ($moreResults) {
		$apiUrl = "$primoSearchApi&offset=$offset";
		$response = file_get_contents($apiUrl);
		if ($response === FALSE) {
			http_response_code(500);
			echo 'Error fetching data';
			exit;
		}

		$data = json_decode($response, true);
		$items = $data['docs'];
		$allResults = array_merge($allResults, $items);
		$moreResults = count($items) >= 10;
		$offset += 10;
}

$bibs = [];
foreach ($allResults as $item) {
	if (isset($item['@id'])) {
		$temp = explode('/', $item['@id']);
		$mms_id = end($temp);
		$bibApi = "$almaBibApi&mms_id=$mms_id";

		$xmlResponse = file_get_contents($bibApi);
		if ($xmlResponse === FALSE) {
			http_response_code(500);
			echo 'Error fetching data';
			exit;
		}

		$xml = simplexml_load_string($xmlResponse);
		$bib = $xml->bib[0];
		$title = rtrim((string) $bib->title[0], '/') ?: '(no title)';
		$tag = $bib->record->controlfield[2];
		$dateString = substr((string) $tag, 0, 6);
		$lmDate = convertToDate($dateString);
		$bibs[] = ['mms_id' => $mms_id, 'title' => $title,  
		'lastModificationDate' => $lmDate];
	}
}

usort($bibs, function($a, $b) {
	// return $b['lastModificationDate'] <=> $a['lastModificationDate'];
	if ($b['lastModificationDate'] == $a['lastModificationDate']) {
        return 0;
    }
    return ($b['lastModificationDate'] < $a['lastModificationDate']) ? -1 : 1;
});

if (count($bibs) > 0){
	$topBibs = array_slice($bibs, 0, 5);
    foreach ($topBibs as $book){
        $title = $book['title'];
        $mms = $book['mms_id'];
        $link = "https://brown.primo.exlibrisgroup.com/discovery/fulldisplay?docid=alma$mms&context=L&vid=01BU_INST:BROWN&lang=en&search_scope=MyInst_and_CI&adaptor=Local%20Search%20Engine&tab=Everything&offset=0";

        $template->setCurrentBlock('ITEMS');
        $template->setVariable('PRIMO_TITLE', $title);
        $template->setVariable('ITEM_URL', $link);
        $template->parseCurrentBlock();
    }
}
//$numresult2 = 0;
// $query2 = "";

// $query2 = "SELECT * FROM items_bruknow_comprehensive
// WHERE item_account = \"$account\"
// ORDER BY item_date DESC
// LIMIT 0 ,5 
// ";
// $result2 = mysqli_query($link,$query2);

// how many items for this account are in MySQL bookplates.items_bruknow table?

//$numresult2 = mysqli_num_rows($result2);


// if ($numresult2 > 0){

// 	while ($row2 = mysqli_fetch_array($result2)){
         
// 		$item_title = $row2['item_title'];
// 		$mms = $row2['item_mmsid'];
// 		$bib = $row2['item_bibno'];

// 		if ($mms != ""){
// 			$link1 = 'https://brown.primo.exlibrisgroup.com/discovery/fulldisplay?docid=alma'.$mms.'&context=L&vid=01BU_INST:BROWN&lang=en&search_scope=MyInst_and_CI&adaptor=Local%20Search%20Engine&tab=Everything&offset=0';
// 			$item_link = $link1;	
// 		}
// 		else {
// 			$link2 = 'https://search.library.brown.edu/catalog/'.$bib;
// 			$item_link = $link2;
// 		}



// /*
// <p>&#187; <a href="https://brown.primo.exlibrisgroup.com/discovery/fulldisplay?docid=alma{PRIMO_MMS}&context=L&vid=01BU_INST:BROWN&lang=en&search_scope=MyInst_and_CI&adaptor=Local%20Search%20Engine&tab=Everything&offset=0" rel="external">
//                 <span class="fund">{PRIMO_TITLE}</span></a><br /> 
               



// Sierra Redirect style

// https://search.library.brown.edu/catalog/b3093906 
// */


// 		$template->setCurrentBlock('ITEMS');
// 		$template->setVariable('PRIMO_TITLE',$item_title);
// 		$template->setVariable('ITEM_URL',$item_link);
// 		$template->parseCurrentBlock();
// 	}
// }
$template->setCurrentBlock('MORE');
$link = 'https://brown.primo.exlibrisgroup.com/discovery/search?query=any,contains,'.$account.'&tab=Everything&search_scope=MyInst_and_CI&vid=01BU_INST:BROWN&&offset=0';
$template->setVariable('LINK',$link);

$template->parseCurrentBlock();


$template->show();



// FUNCTIONS used in Josiah Scraping -- modified from bookplates/honorees.php



?>

