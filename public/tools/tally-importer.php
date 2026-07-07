<?php
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$dataFile = dirname(__DIR__, 2) . '/var/tally_stock.json';
$uploadDir = dirname(__DIR__, 2) . '/var/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
if (!is_dir(dirname($dataFile))) {
    mkdir(dirname($dataFile), 0755, true);
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['tally_file'])) {
    $file = $_FILES['tally_file'];
    if ($file['error'] === UPLOAD_ERR_OK && in_array(
        pathinfo($file['name'], PATHINFO_EXTENSION),
        ['xlsx', 'xls']
    )) {
        $tmpPath = $uploadDir . 'tally_upload.' . pathinfo($file['name'], PATHINFO_EXTENSION);
        move_uploaded_file($file['tmp_name'], $tmpPath);

        try {
            $spreadsheet = IOFactory::load($tmpPath);
            $sheet = $spreadsheet->getSheetByName('Stock Summary') ?? $spreadsheet->getActiveSheet();

            $rows = [];
            $highestRow = $sheet->getHighestRow();
            $dataStartRow = 0;

            for ($r = 1; $r <= min($highestRow, 15); $r++) {
                $val = strtolower(trim((string)$sheet->getCell("A{$r}")->getValue()));
                if ($val === 'particulars') {
                    $dataStartRow = $r + 3;
                    break;
                }
            }
            if ($dataStartRow === 0) $dataStartRow = 8;

            for ($r = $dataStartRow; $r <= $highestRow; $r++) {
                $name = trim((string)$sheet->getCell("A{$r}")->getValue());
                $qty = $sheet->getCell("B{$r}")->getCalculatedValue();
                $rate = $sheet->getCell("C{$r}")->getCalculatedValue();
                $value = $sheet->getCell("D{$r}")->getCalculatedValue();

                if ($name === '' && ($qty === null || $qty === '')) continue;
                if (stripos($name, 'Grand Total') !== false) break;

                $rows[] = [
                    'row' => $r,
                    'name' => $name,
                    'qty' => is_numeric($qty) ? (float)$qty : 0,
                    'rate' => is_numeric($rate) ? round((float)$rate, 2) : 0,
                    'value' => is_numeric($value) ? round((float)$value, 2) : 0,
                ];
            }

            $products = [];
            $i = 0;
            while ($i < count($rows)) {
                $current = $rows[$i];
                $grades = [];
                $gradeQtySum = 0;
                $j = $i + 1;
                while ($j < count($rows)) {
                    $next = $rows[$j];
                    $gradeQtySum += $next['qty'];
                    if ($gradeQtySum > $current['qty'] + 0.01) {
                        break;
                    }
                    $grades[] = $next;
                    if (abs($gradeQtySum - $current['qty']) < 0.01) {
                        break;
                    }
                    $j++;
                }

                if (count($grades) > 0 && abs($gradeQtySum - $current['qty']) < 0.01) {
                    foreach ($grades as $g) {
                        $products[] = [
                            'product' => $current['name'],
                            'grade' => $g['name'],
                            'qty' => (int)$g['qty'],
                            'rate' => $g['rate'],
                            'value' => $g['value'],
                        ];
                    }
                    $i = $j + 1;
                } else {
                    $products[] = [
                        'product' => $current['name'],
                        'grade' => '-',
                        'qty' => (int)$current['qty'],
                        'rate' => $current['rate'],
                        'value' => $current['value'],
                    ];
                    $i++;
                }
            }

            file_put_contents($dataFile, json_encode($products, JSON_PRETTY_PRINT));
            $message = 'Imported ' . count($products) . ' items from ' . $file['name'];
            $messageType = 'success';
        } catch (\Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    } else {
        $message = 'Please upload a valid .xlsx or .xls file';
        $messageType = 'warning';
    }
}

$products = [];
if (file_exists($dataFile)) {
    $products = json_decode(file_get_contents($dataFile), true) ?: [];
}

$search = $_GET['q'] ?? '';
if ($search !== '') {
    $products = array_filter($products, function($p) use ($search) {
        return stripos($p['product'], $search) !== false || stripos($p['grade'], $search) !== false;
    });
}

$totalValue = array_sum(array_column($products, 'value'));
$totalQty = array_sum(array_column($products, 'qty'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tally Stock Importer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; }
        .stock-header { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: white; padding: 2rem; border-radius: 12px; margin-bottom: 1.5rem; }
        .stat-card { background: white; border-radius: 8px; padding: 1rem 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-value { font-size: 1.5rem; font-weight: 700; }
        .table-container { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; }
        .table thead th { background: #f1f5f9; border-bottom: 2px solid #e2e8f0; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .table tbody tr:hover { background: #f8fafc; }
        .grade-badge { background: #dbeafe; color: #1e40af; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500; }
        .upload-zone { border: 2px dashed #cbd5e1; border-radius: 8px; padding: 2rem; text-align: center; background: #f8fafc; }
    </style>
</head>
<body>
<div class="container-fluid py-4" style="max-width: 1200px;">
    <div class="stock-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-1">Tally Stock Importer</h2>
                <p class="mb-0 opacity-75">Import your Tally stock data for quick invoice line items</p>
            </div>
            <a href="../" class="btn btn-outline-light btn-sm">Back to Dashboard</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="upload-zone">
                <form method="post" enctype="multipart/form-data" id="uploadForm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <p class="mt-2 mb-2 text-muted">Upload Tally Excel Export</p>
                    <input type="file" name="tally_file" accept=".xlsx,.xls" class="form-control form-control-sm mb-2" onchange="document.getElementById('uploadForm').submit()">
                    <small class="text-muted">Accepts Stock Summary .xlsx from Tally</small>
                </form>
            </div>
        </div>
        <div class="col-md-8">
            <div class="row g-3">
                <div class="col-4">
                    <div class="stat-card">
                        <div class="text-muted small">Total Items</div>
                        <div class="stat-value text-primary"><?= number_format(count($products)) ?></div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="stat-card">
                        <div class="text-muted small">Total Quantity</div>
                        <div class="stat-value text-success"><?= number_format($totalQty) ?></div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="stat-card">
                        <div class="text-muted small">Total Value (AED)</div>
                        <div class="stat-value text-warning"><?= number_format($totalValue, 2) ?></div>
                    </div>
                </div>
            </div>

            <form method="get" class="mt-3">
                <div class="input-group">
                    <input type="text" name="q" class="form-control" placeholder="Search products or grades..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($search): ?><a href="?" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if (count($products) > 0): ?>
    <div class="table-container">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th style="width: 5%">#</th>
                    <th style="width: 35%">Product</th>
                    <th style="width: 15%">Grade</th>
                    <th style="width: 10%" class="text-end">Qty</th>
                    <th style="width: 15%" class="text-end">Rate (AED)</th>
                    <th style="width: 20%" class="text-end">Value (AED)</th>
                </tr>
            </thead>
            <tbody>
                <?php $n = 0; foreach ($products as $p): $n++; ?>
                <tr>
                    <td class="text-muted"><?= $n ?></td>
                    <td><strong><?= htmlspecialchars($p['product']) ?></strong></td>
                    <td><span class="grade-badge"><?= htmlspecialchars($p['grade']) ?></span></td>
                    <td class="text-end"><?= number_format($p['qty']) ?></td>
                    <td class="text-end"><?= number_format($p['rate'], 2) ?></td>
                    <td class="text-end"><strong><?= number_format($p['value'], 2) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="text-center py-5 text-muted">
        <p>No stock data loaded yet. Upload a Tally Excel export to get started.</p>
    </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
