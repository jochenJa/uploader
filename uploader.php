<?php

# configuration
$max_file_size = isset($_REQUEST['MAX_FILE_SIZE']) ? $_REQUEST['MAX_FILE_SIZE'] : (20 * 1024 * 1024);
$tempScanStore = new TempScanStore($path_www.$path_upload.'scans/', (new TempScanRepository()));

#routing (avoid executing when this file is included in other pages)

if($_REQUEST['a'] !== "uploading") return;

# validate request data
$document_type = isset($_REQUEST['document_type']) ? $_REQUEST['document_type'] : false;
$object_id = isset($_REQUEST['object_id']) ? $_REQUEST['object_id'] : false;
$file_summary = isset($_FILES['file']) ? ($_FILES['file']) : false;
$description = isset($_REQUEST['description']) ? addslashes($_REQUEST['description']) : '';
$timestamp = isset($_REQUEST['date']) ? strtotime($_REQUEST['date']) : time();

#process uploading
if($document_type && $file_summary && $object_id) {
    $uploadedFile = new UploadedFile($file_summary);

    # file size not allowed.
    if( $uploadedFile->exceedsFileSize($max_file_size) ) {
        return failure(3, array('max_size' => format_bytes($max_file_size, 0)));
    }

    # file type not allowed
    require_once($path_class.'mimereader.class');
    $mime = new MimeReader($uploadedFile->tempFilePath());
    if(! in_array($mime->get_type(), MimeType::availableFor($uploadedFile->extension())) ) {
        return failure(2);
    }

    # storing uploaded file.
    $uploadedScan = new UploadedScan(
        DocumentRefererRepository::findBy($document_type, $object_id),
        $uploadedFile,
        $description,
        $timestamp
    );

    return $tempScanStore->addScan($uploadedScan)
        ? true
        : failure(4);
}

function failure($error, $args = array()) {
    header("HTTP/1.0 403 ".trans("error", 'query scans uploading'.$error, 'f', $args));
    return false;
}

class TempScanStore
{
    private $path;
    /**
     * @var TempScanRepository
     */
    private $repository;

    /**
     * TempScanStore constructor.
     * @param $path
     * @param TempScanRepository $repository
     */
    public function __construct($path, TempScanRepository $repository)
    {
        $this->path = $path;
        $this->repository = $repository;
    }

    public function addScan(UploadedScan $scan)
    {
        return (
            move_uploaded_file($scan->currentLocation(), $this->path . $scan->fileName())
            && $this->repository->persist(
                $scan->fileName(),
                $scan->description()
            )
        );
    }

}

class UploadedScan {

    /**
     * @var DocumentReferer
     */
    private $referer;
    /**
     * @var UploadedFile
     */
    private $file;
    private $description;
    private $date;

    /**
     * UploadedScan constructor.
     * @param DocumentReferer $referer
     * @param UploadedFile $file
     * @param $description
     * @param $date
     */
    public function __construct(DocumentReferer $referer, UploadedFile $file, $description, $date)
    {
        $this->referer = $referer;
        $this->file = $file;
        $this->description = $description;
        $this->date = $date;
    }

    function fileName()
    {
        return strtolower(
            implode('_', array(
                $this->referer->reference(),
                'c',
                $this->referer->id(),
                $this->referer->type(),
                time(),
                $this->date,
                md5(time()),
                isset($_SESSION['client_db']) ? $_SESSION['client_db'] : 'live'
            ))
            .'.'.$this->file->extension()
        );
    }

    public function currentLocation() { return $this->file->tempFilePath(); }
    public function description() { return $this->description; }
}

class UploadedFile {

    private $fileName;
    private $tempFilePath;
    private $size;
    private $error;

    /**
     * UploadedFile constructor.
     */
    public function __construct($summary)
    {
        $this->fileName = $summary['name'];
        $this->tempFilePath = $summary['tmp_name'];
        $this->size = $summary['size'];
        $this->error = isset($summary['error']) ? $summary['error'] : 0;
    }

    public function tempFilePath() { return $this->tempFilePath; }
    public function extension() { return strtolower(pathinfo($this->fileName, PATHINFO_EXTENSION)); }

    public function exceedsFileSize($max_file_size)
    {
        return $this->size <= 0 || $this->size > $max_file_size;
    }
}

class DocumentReferer
{
    private $type;
    private $id;
    private $reference;

    public function __construct($type, $id, $reference)
    {
        $this->type = $type;
        $this->id = $id;
        $this->reference = preg_replace('/[^\d\w]/', '', $reference) ?: '000000';
    }

    public function reference() { return $this->reference; }
    public function id() { return $this->id; }
    public function type() { return $this->type;  }
}

class DocumentRefererRepository
{
    public static function findBy($documentType, $object_id)
    {
        $rs = db_query(self::lookupFindByQ($documentType, $object_id));

        return ($rs && db_num_rows($rs))
            ? (new DocumentReferer($documentType, $object_id, db_result($rs)))
            : null
            ;
    }

    public static function findAll($documentType, $company = false, $group = false)
    {
        $limits = $company ? "c.companyID = '".$company."'" . ($group ? " OR c.groupID = '" . $company . "'" : '') : 1;
        $rsq = db_query(sprintf(self::lookupFindAllQ($documentType), $limits));
        $referers = array();
        while($rs = db_fetch_assoc($rsq)) {
            if(! empty($rs['value'])) $referers[ucfirst(preg_replace('/\s{2,}/', ' ', $rs['value']))] = $rs['id'];
        }

        return $referers;
     }

    private static function lookupFindByQ($documentType, $object_id)
    {
        switch($documentType) {
            case DocumentType::INVOICE :
                return "SELECT invoiceno FROM invoices WHERE invoiceID = '" . $object_id . "' LIMIT 1";

            case DocumentType::CONTRACT :
                return "SELECT contractno FROM contracts WHERE contractID = '" . $object_id . "' LIMIT 1";

            case DocumentType::TENDERS :
                return "SELECT u.username FROM car_orders cao LEFT JOIN users u ON u.userID = cao.userID_new WHERE cao.orderID = '" . $object_id . "' LIMIT 1";

            case DocumentType::TRAFFIC_TICKET :
                return "SELECT ca.plate FROM traffic_tickets tt LEFT JOIN cars ca ON ca.carID = tt.carID WHERE tt.traffic_ticketID = '" . $object_id . "' LIMIT 1";

            case DocumentType::DAMAGE :
                return "SELECT ca.plate FROM damages d LEFT JOIN cars ca ON ca.carID = d.carID WHERE d.damageID = '" . $object_id . "' LIMIT 1";

            case DocumentType::DRIVERSTART :
            case DocumentType::DRIVERSTOP :
                return (substr($object_id, 0, 2) == 'ca')
                    ? "SELECT plate FROM cars WHERE carID = '" . substr($object_id, 2) . "' LIMIT 1"
                    : "SELECT ca.plate FROM car_history ch LEFT JOIN cars ca ON ca.carID = ch.carID WHERE ch.car_historyID = '" . $object_id . "' LIMIT 1"
                    ;

            case DocumentType::MAINTENANCE :
                return "SELECT ca.plate FROM maintenance mt LEFT JOIN cars ca ON ca.carID = mt.carID WHERE mt.maintenanceID = '" . $object_id . "' LIMIT 1";

            case DocumentType::CARDOCUMENT :
                return "SELECT plate FROM cars WHERE carID = '" . $object_id . "' LIMIT 1";

            case DocumentType::USERDOCUMENT :
                return "SELECT username FROM users WHERE userID = '" . $object_id . "' LIMIT 1";

            case DocumentType::CARASSISTCARD :
                return "SELECT carID, '1' AS userID FROM cars WHERE carID = '" . $object_id . "' LIMIT 1";
        }
    }

    private static function lookupFindAllQ($documentType)
    {
        switch($documentType)
        {
            case DocumentType::INVOICE :
                return
                    "SELECT i.invoiceID AS id,
                            CONCAT(FROM_UNIXTIME(CAST(i.date_invoice AS CHAR), '%d-%m-%Y'),' : ',CAST(i.invoiceno AS CHAR),' - ',CAST(i.total AS CHAR),' ',UPPER(i.currency)) AS value
                     FROM invoices i LEFT JOIN companies c ON c.companyID = i.companyID
                     WHERE (%s) ORDER BY i.date_invoice DESC, i.invoiceID, i.invoiceno";

            case DocumentType::CONTRACT :
                return
                    "SELECT co.contractID AS id,
                            CONCAT(UPPER(CAST(co.contractno AS CHAR)),' : ',IFNULL(ca.plate, ''),' - ',IFNULL(u.name, ''),' [',FROM_UNIXTIME(co.stamp, '%d-%m-%Y'),']') AS value
                     FROM contracts co LEFT JOIN cars ca ON co.contractID = ca.contractID LEFT JOIN users u ON u.userID = ca.userID LEFT JOIN companies c ON c.companyID = co.cID
                     WHERE (%s) ORDER BY co.contractID, ca.plate, u.name";

            case DocumentType::TENDERS :
                return
                    "SELECT cao.orderID AS id,
                            CONCAT(CAST(u.name AS CHAR),' : ',IFNULL(bc.category, ''),' - ',IFNULL(ma.make, ''),' ',IFNULL(bc.model, '')) AS value
                     FROM car_orders cao LEFT JOIN users u ON u.userID = cao.userID_new LEFT JOIN companies c ON c.companyID = u.companyID LEFT JOIN budgetcats bc ON bc.budgetcatID = u.budgetcatID LEFT JOIN makes ma ON ma.makeID = bc.makeID
                     WHERE (%s) ORDER BY u.name";

            case DocumentType::TRAFFIC_TICKET :
                return
                    "SELECT tt.traffic_ticketID AS id,
                            CONCAT(FROM_UNIXTIME(CAST(tt.date AS CHAR),'%d-%m-%Y'),' : ',IFNULL(ca.plate, ''),' - ',IFNULL(u.name, ''),' (',IFNULL(tt.pvno, ''),')') AS value
                     FROM traffic_tickets tt LEFT JOIN cars ca ON tt.carID = ca.carID LEFT JOIN users u ON u.userID = tt.userID LEFT JOIN companies c ON c.companyID = u.companyID
                     WHERE (%s) ORDER BY tt.date DESC, tt.pvno, ca.plate, u.name";

            case DocumentType::DAMAGE :
                return
                    "SELECT d.damageID AS id,
                            CONCAT(FROM_UNIXTIME(CAST(d.date AS CHAR), '%d-%m-%Y'),' : ',IFNULL(ca.plate, ''),' - ',IFNULL(u.name, ''),' (#',CAST(d.fileno AS CHAR),')') AS value
                    FROM damages d LEFT JOIN cars ca ON d.carID = ca.carID LEFT JOIN users u ON u.userID = d.userID LEFT JOIN companies c ON c.companyID = u.companyID
                    WHERE (%s) ORDER BY d.date DESC, d.damageno, ca.plate, u.name";

            case DocumentType::DRIVERSTART :
            case DocumentType::DRIVERSTOP :
                return
                    "(SELECT ch.car_historyID AS id,
                            CONCAT(FROM_UNIXTIME(CAST(ch.date_start AS CHAR), '%d-%m-%Y'),' > ',FROM_UNIXTIME(CAST(ch.date_end AS CHAR), '%d-%m-%Y'),' : ',IFNULL(ca.plate, ''),' - ',IFNULL(u.name, ''),'') AS value,
                            ch.date_start AS date
                    FROM car_history ch LEFT JOIN cars ca ON ch.carID = ca.carID LEFT JOIN users u ON u.userID = ch.userID LEFT JOIN companies c ON c.companyID = u.companyID
                    WHERE (%s) AND (CONCAT(ch.date_start,ch.date_end,ca.plate,u.name) IS NOT NULL))
                    UNION
                    (SELECT CONCAT('ca',ca.carID) AS id,
                            CONCAT(FROM_UNIXTIME(CAST(ca.date_start AS CHAR), '%d-%m-%Y'),' : ',IFNULL(ca.plate, ''),' - ',IFNULL(u.name, ''),'') AS value,
                            ca.date_start AS date
                    FROM cars ca LEFT JOIN users u ON u.userID = ca.userID LEFT JOIN companies c ON c.companyID = u.companyID
                    WHERE (%s) AND (CONCAT(ca.date_start,ca.plate,u.name) IS NOT NULL))
                    ORDER BY date";

            case DocumentType::MAINTENANCE :
                return
                    "SELECT mt.maintenanceID AS id,
                            CONCAT(FROM_UNIXTIME(CAST(mt.date_garage AS CHAR), '%d-%m-%Y'),' : ',IFNULL(ca.plate, ''),' - ',CAST(SUM(mti.price) AS CHAR)) AS value
                    FROM maintenance mt LEFT JOIN maintenance_lines mti ON mt.maintenanceID = mti.maintenanceID LEFT JOIN cars ca ON mt.carID = ca.carID LEFT JOIN users u ON u.userID = ca.userID LEFT JOIN companies c ON c.companyID = u.companyID LEFT JOIN users ug ON mt.garageID = ug.userID LEFT JOIN companies cg ON ug.companyID = cg.companyID
                    WHERE (%s) GROUP BY mt.maintenanceID ORDER BY mt.date_garage DESC, ca.plate";

            case DocumentType::CARDOCUMENT :
                return
                    "SELECT ca.carID as id,
                            CONCAT(ca.plate,' : ',m.make,' ',ca.model,' - ',u.name) as value
                    FROM cars ca LEFT JOIN makes m ON m.makeID = ca.makeID LEFT JOIN users u ON u.userID = ca.userID LEFT JOIN companies c ON c.companyID = u.companyID
                    WHERE (%s) ORDER BY ca.plate";

            case DocumentType::USERDOCUMENT :
                return
                    "SELECT u.userID as id,
                            CONCAT(c.company,' - ',u.name) as value
                    FROM users u LEFT JOIN companies c ON c.companyID = u.companyID
                    WHERE (%s) ORDER BY c.company, u.name";
        }
    }
}

class TempScanRepository
{
    public static function persist($filename, $description)
    {
        return db_query(sprintf(
            "INSERT INTO scans_tmp (filename, description, stamp) VALUES ('%s','%s', UNIX_TIMESTAMP())",
            $filename,
            $description
        ));
    }
}

class DocumentType
{
    const INVOICE       = 'i';
    const MAINTENANCE   = 'mt';
    const CARDOCUMENT   = 'cd';
    const DRIVERSTART   = 'dstrt';
    const DRIVERSTOP    = 'dstop';
    const CARASSISTCARD = 'cac';
    const CONTRACT      = 'co';
    const TRAFFIC_TICKET = 'tt';
    const TENDERS       = 'ten';
    const DAMAGE        = 'd';
    const USERDOCUMENT  = 'ud';

    private static $arr_doctypes;

    private static function init()
    {
        if(! is_array(self::$arr_doctypes)) {
            self::$arr_doctypes = array(
                'invoices'      => self::INVOICE,
                'contracts'     => self::CONTRACT,
                'tenders'       => self::TENDERS,
                'traffictickets'=> self::TRAFFIC_TICKET,
                'damages'       => self::DAMAGE,
                'maintenance'   => self::MAINTENANCE,
                'userdocuments' => self::USERDOCUMENT,
                'cardocuments'  => self::CARDOCUMENT,
                'driverstart'   => self::DRIVERSTART,
                'driverstop'    => self::DRIVERSTOP,
                'carassistancecard' => self::CARASSISTCARD
            );
        }
    }

    public static function scanTypes()
    {
        self::init();
        return self::$arr_doctypes;
    }

    public static function allowed($documentType)
    {
        self::init();
        return in_array($documentType, self::$arr_doctypes);
    }

    public static function text($doctype)
    {
        self::init();
        return array_search($doctype, self::$arr_doctypes);
    }
}

class MimeType
{
    private static $mimetypes;

    private static function init() {
        if(! self::$mimetypes) {
            self::$mimetypes = array(
                'pdf' => array('application/pdf', 'application/x-pdf', 'application/acrobat', 'applications/vnd.pdf', 'text/pdf', 'text/x-pdf'),
                'bmp' => array('image/bmp'),
                'png' => array('image/png'),
                'jpg' => array('image/jpeg'),
                'csv' => array('text/csv', ''),
                'txt' => array('text/plain', ''),
                'msg' => array('application/vnd.ms-outlook', 'application/office'),
                'ppt' => array('application/vnd.ms-powerpoint', 'application/office'),
                'pptx' => array('application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.ms-powerpoint'),
                'doc' => array('application/vnd.ms-word', 'application/msword', 'application/doc', 'appl/text', 'application/vnd.msword', 'application/winword', 'application/word', 'application/x-msw6', 'application/x-msword', 'application/office'),
                'docx' => array('application/vnd.ms-word', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword', 'application/doc', 'appl/text', 'application/vnd.msword', 'application/winword', 'application/word', 'application/x-msw6', 'application/x-msword', 'application/office'),
                'xls' => array('application/vnd.ms-excel', 'application/msexcel', 'application/x-msexcel', 'application/x-ms-excel', 'application/x-excel', 'application/x-dos_ms_excel', 'application/xls'),
                'xlsx' => array('application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/msexcel', 'application/x-msexcel', 'application/x-ms-excel', 'application/x-excel', 'application/x-dos_ms_excel', 'application/xls'),
                'xml' => array('application/xml'),
                'zip' => array('application/zip', 'application/x-7z-compressed'),
                'rar' => array('application/x-rar-compressed'),
                'tar' => array('application/x-tar'),
            );
        }
    }

    public static function availableFor($extension)
    {
        self::init();
        return array_key_exists($extension, self::$mimetypes) ? self::$mimetypes[$extension] : array();
    }

    public static function allowedExtensions()
    {
        self::init();
        return array_keys(self::$mimetypes);
    }
}
