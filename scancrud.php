<?php

##### INTERFACE CONFIG #####
$path_includes        = "../";
$path_import          = $path_includes."www/upload/scans/";
$path_scanlibrary     = $path_includes."scans/";

$thismenu   = substr(strrchr(dirname(__FILE__), $dir_seperator), 1);
$thispage   = $_GET['p']; # page name
$thisaction = ($_GET['a'] == 'add' ? 'upload' : $_GET['a']); # page action
$thistype   = $_GET['t']; # form document type
$thispid    = "scan"; # name of the GET var representing the primary key
$thistables = array("sc" => "scans", "ca" => "cars", "u" => "users", "c" => "companies"); # tables used in the main query
$thispk     = "scanID"; # the primary key of the first table
$thissearch = true; # sets if the search is enabled by default

include($path_secure."comments.cfg");
include($path_lib."permissions.inc");
require_once($path_func."/scans.inc");

if (empty($thisaction) || $thisaction == "delete" || $thisaction == "uploading"){
    ksort($arr_scans_mime);

    $thisheader = array(
        "file"      => array("", array_change_key_case(array_combine(array_keys($arr_scans_mime), array_keys($arr_scans_mime)), CASE_UPPER)),
        "date_scan" => array("sc.date_scan", "d"),
        "plate"     => array("ca.plate", "y"),
        "user"      => array("u.name", "y"),
        "type"      => array("sc.doctype", $arr_doctypes),
        "description" => array("sc.description", "y"),
        "date_doc"  => array("sc.date_doc", "d"),
        "actions"   => array("", "n")
    ); # header array for this page, format : "name" => array("db table.field", "searchable or not")
    $thisdefault = "date_scan.desc"; # default order value (headername.direction)
}elseif ($thisaction == "view" || $thisaction == "upload" || $thisaction == "add" || $thisaction == "edit" || $thisaction == "change"){
    # check GET vars
    $thisid = (isset($_GET[$thispid]) && ctype_digit($_GET[$thispid])) ? $_GET[$thispid] : false;

    if ($thisid){
        $sql_scan = "SELECT token, doctype FROM scans WHERE scanID = '".$thisid."'";
        $res_scan = db_query($sql_scan);

        if (db_num_rows($res_scan))
            list($token, $thistype) = db_fetch_row($res_scan);
    }elseif (in_array($_POST['doctype'], $arr_doctypes)){
        $thistype = $_POST['doctype'];
    }elseif ($thisaction <> 'upload'){
        echo '<meta http-equiv="refresh" content="0; URL='.urls(array("p" => $thispage), 'c').'" />';
        exit();
    }

    $recordIDs = DocumentRefererRepository::findAll(
        $thistype,
        ($my->level && calc_lvl('s-f')) ? $my->companyID : false,
        is_array($_SESSION['company'])
    );

    $types = array(
        DocumentType::INVOICE         => 'invoice',
        DocumentType::CONTRACT        => 'contract',
        DocumentType::TENDERS         => 'car order',
        DocumentType::TRAFFIC_TICKET  => 'traffic ticket',
        DocumentType::DAMAGE          => 'damage',
        DocumentType::DRIVERSTART     => 'driverstart',
        DocumentType::DRIVERSTOP      => 'driverstop',
        DocumentType::MAINTENANCE     => 'maintenance',
        DocumentType::CARDOCUMENT     => 'car',
        DocumentType::USERDOCUMENT    => 'user'
    );

    $thisheader = array(
        #"plate"             => array("carID", calc_lvl('s-g'), calc_lvl('f-g'), 0, arr_cars('', false), array("p" => "cars", "a" => "view", "car" => "%carID"), calc_lvl('s-g')),
        #"driver"            => array("userID", calc_lvl('s-g'), calc_lvl('f-g'), 0, arr_companyusers(), array("p" => "users", "a" => "view", "user" => "%userID"), calc_lvl('s-g')),
        "doctype"           => array("doctype", calc_lvl('s-g'), 0, 0, $arr_doctypes),
        "date_doc"          => array("date_doc", calc_lvl('s-g'), calc_lvl('s-g'), 0, "[[:digit:]]{9,10}", "date"),
        "date_scan"         => array("date_scan", calc_lvl('s-g'), 0, 0, "[[:digit:]]{9,10}", "datetime"),
        $types[$thistype]   => array("recordID", calc_lvl('s-g'), calc_lvl('f-g'), 0, $recordIDs),
        "description"       => array("description", calc_lvl('s-g'), calc_lvl('f-g'), 0, "(.*)")
    ); # header array for this page, format : "name" => array("db table.field", "level needed for view", "level needed for edit", "level needed for new", "array for dropdown" or else "regex", optional "array for linking in view mode" (if a value is preceded with % it's a var in the $data object))
    $thisdouble   = array(); # field(s) used to check for double rows
}
##### /INTERFACE CONFIG #####
else{
    echo '<meta http-equiv="refresh" content="0; URL='.urls(array("p" => $thispage), 'c').'" />';
    exit();
}
$thisfirsttable = array_values($thistables); $thisfirsttable = $thisfirsttable[0];

# Add or Change
if (($thisaction == "add" || $thisaction == "change") && isset($_POST)){
    $post_count = count($_POST);

    # convert dates to timestamps
    $convert_date = array("date_doc");
    convert_dates($convert_date);

    # check posted vars for correctness
    include($path_func."varcheck.inc");
    $error = varcheck();

    # check posted values
    $_POST = array_map("check_values", $_POST);

    if (count($error[$thisaction]) == 0 && $post_count){
        if ($thisaction == "change" && $thisedit && strlen($_POST['token']) == 32 && preg_match('/^[\w\d]*$/', $_POST['token'])){
            # $doubles = doubles($thisfirsttable, $thispk);

            if (!count($doubles) && $token == $_POST['token']){
                $flag_table = (substr($_POST['recordID'], 0, 2) == 'ca' ? true : false);
                if ($flag_table) $_POST['recordID'] = preg_replace('/\D/', '', $_POST['recordID']);

                $sql_update = "UPDATE ".$thisfirsttable." SET ";
                foreach ($thisheader as $key => $value){
                    if ($value[0] != "NEWLINE" && $value[0]{0} != '|')
                        $sql_update .= $value[0]." = '".$_POST[$value[0]]."', ";
                }
                $sql_update = substr($sql_update, 0, (strlen($sql_update) - 2));
                if ($flag_table) $sql_update .= ", flag_table = 'ca'";
                $sql_update .= " WHERE ".$thispk." = '".$thisid."'";

                $query[$thisaction] = db_query($sql_update);

                if ($query[$thisaction])
                    $thisaction = "view";
                else
                    $thisaction = "edit";
            }else{
                $query[$thisaction] = 2;
                $thisaction = "edit";
            }
        }
    }else{
        switch ($thisaction){
            case "add" : $thisaction = "new"; break;
            case "change" : $thisaction = "edit"; break;
        }
    }

    # strip slashes for minor incompatibility
    foreach ($_POST as $key => $value)
        $_POST[$key] = stripslashes(str_replace("\\r\\n", "<br />", $value));
}

# Delete
if ($thisaction == "delete" && ctype_digit($_GET[$thispid])){
    if ($thisdelete && ctype_digit($_GET['chk'])){
        # DELETE FILE FYSICALLY!
        $sql = "SELECT filename FROM scans WHERE ".$thispk." = '".$_GET[$thispid]."' AND stamp = '".strrev($_GET['chk'])."' LIMIT 1";
        $res = db_query($sql);

        if (db_num_rows($res)){
            $file = db_result($res);

            $file_dir = preg_split('/_/', $file);
            $file_dir = $file_dir[0];

            $dir = "../scans/".strtolower(substr($file_dir, 0, 1))."/".strtolower($file_dir)."/";
            @chmod($dir.$file, 0755);
            @unlink($dir.$file);

            $sql = "DELETE FROM scans WHERE ".$thispk." = '".$_GET[$thispid]."'";
            $query[$thisaction] = db_query($sql);
        }
    }else
        $query[$thisaction] = 2;
}

switch ($thisaction){
    case "view" : case "add" : case "new" : case "edit" : case "change" :
        include($path_secure.$thismenu."/".$thispage.".view.inc");
        break;

    case "upload" :
        $twig = new Twig_Environment(new Twig_Loader_Filesystem("../includes/tmpl/web/upload"));
        $twig->addFilter(
            new Twig_SimpleFilter('trans', function($text, $cat = 'general') {
                global $cookie_lg;
                return trans_str(sprintf('#%s#%s##', $cat, $text), $cookie_lg);
            })
        );
        $twig->addFilter(new Twig_SimpleFilter('f', function($text) { return ucfirst(mb_strtolower($text)); }));
        $twig->addFunction(new Twig_SimpleFunction('breadcrumb', 'breadcrumb'));
        $twig->addFunction(new Twig_SimpleFunction('bookmark', 'bookmarks_edit'));

        $data = array(
            'page' => $thispage,
            'action' => $thisaction,
            'uploadUrl' => 'ajax.php?a=uploading&id=999',
            'docType' => $_POST['doctype'],
            'docTypeTxt' => DocumentType::text($_POST['doctype']),
            'mimeTypes' => MimeType::allowedExtensions()
        );

        echo $twig->render('upload.html.twig', $data);
        break;

    default :
        include($path_secure.$thismenu."/".$thispage.".list.inc");
}
