<?php
require_once __DIR__ . '/includes/render_status_page.php';

renderStatusPage([
    'page_title' => 'ยื่นเอกสาร',
    'page_file' => 'document.php',
    'status_column' => 'submit_doc',
    'note_column' => 'submit_doc_note',
    'update_endpoint' => './update/update_submit_doc.php',
    'note_options' => ['ไม่มารายงานตัว', 'คุณสมบัติไม่เป็นไปตามประกาศ'],
    'required_note_statuses' => ['F'],
    'show_note_statuses' => ['F'],
]);
