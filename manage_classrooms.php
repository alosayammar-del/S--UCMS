<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    exit('غير مصرح لك بالدخول.');
}

$userId = (int) $_SESSION['user_id'];

$checkAdmin = $pdo->prepare(
    "SELECT id, full_name, role, status FROM users WHERE id = ? LIMIT 1"
);
$checkAdmin->execute([$userId]);
$admin = $checkAdmin->fetch();

if (!$admin || $admin['role'] !== 'administrator' || $admin['status'] !== 'active') {
    session_destroy();
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['classrooms_csrf'])) {
    $_SESSION['classrooms_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['classrooms_csrf'], $csrfToken)) {
        $message = 'انتهت صلاحية الطلب. أعد تحميل الصفحة.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $roomCode = trim($_POST['room_code'] ?? '');
                $building = trim($_POST['building'] ?? '');
                $capacity = filter_var($_POST['capacity'] ?? '', FILTER_VALIDATE_INT);
                $status = $_POST['status'] ?? 'available';

                if ($roomCode === '' || $building === '' || $capacity === false || $capacity < 1) {
                    throw new Exception('يرجى إدخال جميع البيانات بشكل صحيح.');
                }

                if (!in_array($status, ['available', 'unavailable'], true)) {
                    throw new Exception('حالة القاعة غير صحيحة.');
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE classrooms
                         SET room_code = ?, building = ?, capacity = ?, status = ?
                         WHERE id = ?"
                    );
                    $stmt->execute([$roomCode, $building, $capacity, $status, $id]);
                    $message = 'تم تحديث بيانات القاعة بنجاح.';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO classrooms (room_code, building, capacity, status)
                         VALUES (?, ?, ?, ?)"
                    );
                    $stmt->execute([$roomCode, $building, $capacity, $status]);
                    $message = 'تمت إضافة القاعة بنجاح.';
                }
            } elseif ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);

                if ($id < 1) {
                    throw new Exception('رقم القاعة غير صحيح.');
                }

                $check = $pdo->prepare(
                    "SELECT COUNT(*) FROM schedules WHERE classroom_id = ?"
                );
                $check->execute([$id]);

                if ((int) $check->fetchColumn() > 0) {
                    throw new Exception('لا يمكن حذف القاعة لأنها مرتبطة بجداول دراسية.');
                }

                $stmt = $pdo->prepare("DELETE FROM classrooms WHERE id = ?");
                $stmt->execute([$id]);

                $message = 'تم حذف القاعة بنجاح.';
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $message = 'رمز القاعة مستخدم مسبقا أو توجد بيانات مرتبطة بها.';
            } else {
                error_log($e->getMessage());
                $message = 'حدث خطأ أثناء تنفيذ العملية.';
            }
            $messageType = 'error';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

$editRoom = null;

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM classrooms WHERE id = ?");
    $stmt->execute([$editId]);
    $editRoom = $stmt->fetch();
}

$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare(
        "SELECT * FROM classrooms
         WHERE room_code LIKE ? OR building LIKE ?
         ORDER BY id DESC"
    );
    $term = '%' . $search . '%';
    $stmt->execute([$term, $term]);
} else {
    $stmt = $pdo->query("SELECT * FROM classrooms ORDER BY id DESC");
}

$classrooms = $stmt->fetchAll();

$totalStmt = $pdo->query("SELECT COUNT(*) FROM classrooms");
$totalClassrooms = (int) $totalStmt->fetchColumn();

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة القاعات الدراسية - S-UCMS</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            background: #f3f6fc;
            color: #17324d;
            font-family: Tahoma, Arial, sans-serif;
        }

        .topbar {
            background: #142f4c;
            color: white;
            padding: 20px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .topbar a {
            color: white;
            text-decoration: none;
            background: #294c70;
            padding: 10px 15px;
            border-radius: 7px;
            font-size: 13px;
        }

        .container {
            max-width: 1100px;
            margin: 25px auto;
        }

        .card {
            background: white;
            padding: 24px;
            border-radius: 14px;
            margin-bottom: 22px;
            box-shadow: 0 3px 14px rgba(20, 47, 76, 0.05);
        }

        .total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #718096;
        }

        .total strong {
            color: #167b91;
            font-size: 25px;
        }

        h3 {
            margin-top: 0;
            margin-bottom: 22px;
            font-size: 17px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 17px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 13px;
            border: 1px solid #dce3ec;
            border-radius: 8px;
            background: white;
            color: #17324d;
            font-size: 14px;
        }

        button, .btn {
            border: 0;
            padding: 12px 18px;
            border-radius: 8px;
            background: #167b91;
            color: white;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            display: inline-block;
        }

        .cancel {
            background: #718096;
        }

        .delete {
            background: #c94b4b;
        }

        .edit {
            background: #315c83;
        }

        .form-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .message {
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            background: #e4f5eb;
            color: #216b40;
        }

        .message.error {
            background: #fdeaea;
            color: #a52b2b;
        }

        .search {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
        }

        .search input {
            flex: 1;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 650px;
            border-collapse: collapse;
        }

        th, td {
            padding: 14px 10px;
            text-align: right;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        th {
            background: #f4f7fb;
            color: #53677e;
        }

        .status {
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
        }

        .available {
            background: #e3f5e9;
            color: #237344;
        }

        .unavailable {
            background: #fde9e9;
            color: #a83232;
        }

        .actions {
            display: flex;
            gap: 7px;
        }

        .actions form {
            margin: 0;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .card {
                padding: 17px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="topbar">
    <strong>إدارة القاعات الدراسية</strong>
    <a href="dashboard.php">العودة إلى لوحة التحكم</a>
</div>

<div class="container">

    <?php if ($message !== ''): ?>
        <div class="message <?= $messageType === 'error' ? 'error' : '' ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <div class="card total">
        <span>إجمالي القاعات الدراسية</span>
        <strong><?= $totalClassrooms ?></strong>
    </div>

    <div class="card">
        <h3><?= $editRoom ? 'تعديل بيانات القاعة' : 'إضافة قاعة دراسية جديدة' ?></h3>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['classrooms_csrf']) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($editRoom['id'] ?? 0) ?>">

            <div class="form-grid">
                <div>
                    <label>رمز القاعة</label>
                    <input type="text" name="room_code" required maxlength="50"
                           value="<?= e($editRoom['room_code'] ?? '') ?>"
                           placeholder="مثال: A101">
                </div>

                <div>
                    <label>المبنى</label>
                    <input type="text" name="building" required maxlength="100"
                           value="<?= e($editRoom['building'] ?? '') ?>"
                           placeholder="مثال: المبنى الرئيسي">
                </div>

                <div>
                    <label>السعة الاستيعابية</label>
                    <input type="number" name="capacity" required min="1"
                           value="<?= e($editRoom['capacity'] ?? '') ?>"
                           placeholder="عدد الطلاب">
                </div>

                <div>
                    <label>حالة القاعة</label>
                    <select name="status">
                        <option value="available"
                            <?= (($editRoom['status'] ?? 'available') === 'available') ? 'selected' : '' ?>>
                            متاحة
                        </option>
                        <option value="unavailable"
                            <?= (($editRoom['status'] ?? '') === 'unavailable') ? 'selected' : '' ?>>
                            غير متاحة
                        </option>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit"><?= $editRoom ? 'حفظ التعديلات' : 'إضافة القاعة' ?></button>

                <?php if ($editRoom): ?>
                    <a class="btn cancel" href="manage_classrooms.php">إلغاء</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>قائمة القاعات الدراسية</h3>

        <form method="GET" class="search">
            <input type="text" name="search" value="<?= e($search) ?>"
                   placeholder="ابحث برمز القاعة أو اسم المبنى...">
            <button type="submit">بحث</button>
            <a href="manage_classrooms.php" class="btn cancel">عرض الكل</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>رمز القاعة</th>
                        <th>المبنى</th>
                        <th>السعة</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($classrooms) > 0): ?>
                        <?php foreach ($classrooms as $room): ?>
                            <tr>
                                <td><?= e($room['id']) ?></td>
                                <td><?= e($room['room_code']) ?></td>
                                <td><?= e($room['building']) ?></td>
                                <td><?= e($room['capacity']) ?></td>
                                <td>
                                    <?php if ($room['status'] === 'available'): ?>
                                        <span class="status available">متاحة</span>
                                    <?php else: ?>
                                        <span class="status unavailable">غير متاحة</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <a class="btn edit"
                                           href="manage_classrooms.php?edit=<?= e($room['id']) ?>">
                                            تعديل
                                        </a>

                                        <form method="POST"
                                              onsubmit="return confirm('هل أنت متأكد من حذف هذه القاعة؟');">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($_SESSION['classrooms_csrf']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id"
                                                   value="<?= e($room['id']) ?>">
                                            <button type="submit" class="delete">حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center;padding:25px;color:#8995a5">
                                لا توجد قاعات دراسية لعرضها.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

</body>
</html>
