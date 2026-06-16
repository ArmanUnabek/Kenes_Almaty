<?php
require_once '../config.php';
require_once '../auth_middleware.php';

checkAuth();
$db = getDBConnection();
$type = $_GET['type'] ?? 'kpi';

$html = '<h1>OS Journal Export</h1><p>Тип: ' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</p>';
if ($type === 'kpi') {
    $incoming = (int)$db->query("SELECT COUNT(*) FROM incoming_letters")->fetchColumn();
    $outgoing = (int)$db->query("SELECT COUNT(*) FROM outgoing_letters")->fetchColumn();
    $html .= "<p>Входящие: {$incoming}</p><p>Исходящие: {$outgoing}</p>";
}

if (class_exists('\\Mpdf\\Mpdf')) {
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8']);
    $mpdf->WriteHTML($html);
    $mpdf->Output('report.pdf', \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo $html;

