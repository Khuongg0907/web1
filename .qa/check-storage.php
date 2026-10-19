<?php
$dir = getenv('MONO_DATA_DIR');
$db = new PDO('sqlite:'.$dir.'/shop.sqlite');
$result = ['integrity'=>$db->query('PRAGMA integrity_check')->fetchColumn()];
foreach (['users','orders','products'] as $table) $result[$table] = (int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
$result['has_active_column'] = in_array('active', array_column($db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
$result['uploaded_images'] = count(glob($dir.'/uploads/*') ?: []);
echo json_encode($result), PHP_EOL;
