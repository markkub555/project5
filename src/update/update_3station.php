<?php
require_once '../config/db.php';
require_once __DIR__ . '/status_update_helper.php';

handleStatusUpdate($conn, 'station3_test', 'station3_test_note', ['F']);
