<?php
require_once '../config/db.php';
require_once __DIR__ . '/status_update_helper.php';

handleStatusUpdate($conn, 'fingerprint_check', 'fingerprint_check_note', ['F']);
