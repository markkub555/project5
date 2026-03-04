<?php
require_once 'config/db.php';

$exam_year = $_POST['exam_year'];
$file = $_FILES['file']['tmp_name'];

if (($handle = fopen($file, "r")) !== FALSE) {

    fgetcsv($handle); // ข้าม header

    $sql = "INSERT INTO applicantname 
        (exam_year, id, idcode, prefix, firstname, lastname)
        VALUES (?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (empty($data[0]) || !is_numeric($data[0])) {
            continue;
        }
        $stmt->execute([
            $exam_year,
            $data[0], // id
            $data[1], // idcode
            $data[2], // prefix
            $data[3], // firstname
            $data[4]  // lastname
        ]);
    }

    fclose($handle);
    echo "นำเข้าข้อมูลเรียบร้อย";
}
