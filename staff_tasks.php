<?php
session_start();

require_once __DIR__ . '/config/database.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// التحقق من بيانات الموظف
$userStmt = $pdo->prepare("
    SELECT id, full_name, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['role'] !== 'staff' || $user['status'] !== 'active') {
    header('Location: index.php');
    exit;
}

$fullName = $user['full_name'];
$message = '';
$messageType = '';

// معالجة إضافة أو تعديل أو حذف المهام
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    try {

        if ($action === 'add') {

            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $dueDate = $_POST['due_date'] ?? '';

            if ($title === '') {
                throw new Exception('يرجى إدخال عنوان المهمة.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO staff_tasks
                    (staff_id, title, description, due_date)
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                $title,
                $description ?: null,
                $dueDate ?: null
            ]);

            $message = 'تمت إضافة المهمة بنجاح.';
            $messageType = 'success';

        } elseif ($action === 'update') {

            $taskId = filter_input(
                INPUT_POST,
                'task_id',
                FILTER_VALIDATE_INT
            );

            $status = $_POST['status'] ?? '';

            $allowedStatuses = [
                'pending',
                'in_progress',
                'completed'
            ];

            if (!$taskId || !in_array($status, $allowedStatuses, true)) {
                throw new Exception('بيانات تحديث المهمة غير صحيحة.');
            }

            $stmt = $pdo->prepare("
                UPDATE staff_tasks
                SET status = ?
                WHERE id = ?
                  AND staff_id = ?
            ");

            $stmt->execute([$status, $taskId, $userId]);

            $message = $stmt->rowCount() > 0
                ? 'تم تحديث حالة المهمة بنجاح.'
                : 'لم يتم العثور على المهمة أو لم تتغير حالتها.';

            $messageType = 'success';

        } elseif ($action === 'delete') {

            $taskId = filter_input(
                INPUT_POST,
                'task_id',
                FILTER_VALIDATE_INT
            );

            if (!$taskId) {
                throw new Exception('رقم المهمة غير صحيح.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM staff_tasks
                WHERE id = ?
                  AND staff_id = ?
            ");

            $stmt->execute([$taskId, $userId]);

            if ($stmt->rowCount() > 0) {
                $message = 'تم حذف المهمة بنجاح.';
                $messageType = 'success';
            } else {
                $message = 'المهمة غير موجودة.';
                $messageType = 'error';
            }
        }

    } catch (Exception $e) {
        $message = $e instanceof PDOException
            ? 'حدث خطأ أثناء تنفيذ العملية.'
            : $e->getMessage();

        $messageType = 'error';
    }
}

// جلب مهام الموظف
$tasksStmt = $pdo->prepare("
    SELECT *
    FROM staff_tasks
    WHERE staff_id = ?
    ORDER BY created_at DESC
");
$tasksStmt->execute([$userId]);
$tasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

// حساب الإحصائيات
$totalTasks = count($tasks);
$pendingTasks = 0;
$progressTasks = 0;
$completedTasks = 0;

foreach ($tasks as $task) {
    if ($task['status'] === 'pending') {
        $pendingTasks++;
    } elseif ($task['status'] === 'in_progress') {
        $progressTasks++;
    } elseif ($task['status'] === 'completed') {
        $completedTasks++;
    }
}

// ترجمة الحالة
function taskStatus($status)
{
    $statuses = [
        'pending' => 'لم تبدأ',
        'in_progress' => 'قيد التنفيذ',
        'completed' => 'مكتملة'
    ];

    return $statuses[$status] ?? $status;
}

function taskStatusClass($status)
{
    $classes = [
        'pending' => 'pending',
        'in_progress' => 'progress',
        'completed' => 'completed'
    ];

    return $classes[$status] ?? '';
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>المهام الإدارية - S-UCMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Tahoma, Arial, sans-serif;
            background: #f4f7fb;
            color: #263445;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        .header {
            background: linear-gradient(135deg, #173b68, #2463a6);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
        }

        .header h2 {
            margin: 0 0 10px;
        }

        .header p {
            margin: 0;
            opacity: .9;
        }

        .back-link {
            display: inline-block;
            margin-top: 18px;
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,.15);
            padding: 10px 16px;
            border-radius: 8px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .stat-card h3 {
            margin: 0 0 12px;
            font-size: 15px;
            color: #64748b;
        }

        .stat-card strong {
            font-size: 27px;
            color: #173b68;
        }

        .section {
            background: white;
            padding: 22px;
            border-radius: 14px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
            margin-bottom: 25px;
        }

        .section h3 {
            margin-top: 0;
            color: #173b68;
        }

        .alert {
            padding: 14px;
            border-radius: 9px;
            margin-bottom: 20px;
        }

        .alert.success {
            background: #e8f7ed;
            color: #18753b;
        }

        .alert.error {
            background: #fdecec;
            color: #b42318;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #334155;
            font-size: 14px;
        }

        input, textarea, select {
            width: 100%;
            padding: 11px;
            border: 1px solid #d6deea;
            border-radius: 8px;
            margin-bottom: 16px;
            font-family: inherit;
            font-size: 14px;
            background: white;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        button {
            border: none;
            border-radius: 7px;
            padding: 11px 17px;
            font-family: inherit;
            cursor: pointer;
        }

        .add-button {
            background: #2463a6;
            color: white;
        }

        .add-button:hover {
            background: #173b68;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 700px;
            border-collapse: collapse;
        }

        th, td {
            padding: 13px;
            text-align: right;
            border-bottom: 1px solid #e8edf3;
            vertical-align: top;
        }

        th {
            background: #f2f6fb;
            color: #173b68;
        }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            white-space: nowrap;
        }

        .pending {
            background: #fff4d6;
            color: #946200;
        }

        .progress {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .completed {
            background: #e5f7eb;
            color: #18753b;
        }

        .update-form {
            min-width: 140px;
        }

        .update-button {
            width: 100%;
            background: #2463a6;
            color: white;
        }

        .delete-button {
            width: 100%;
            background: #fde8e8;
            color: #b42318;
            margin-top: 7px;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #64748b;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .header {
                padding: 20px;
            }

            .section {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h2>إدارة المهام الإدارية</h2>

        <p>
            مرحبا، <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>
        </p>

        <a href="dashboard.php" class="back-link">
            العودة إلى لوحة التحكم
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert <?= htmlspecialchars($messageType) ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="stats">

        <div class="stat-card">
            <h3>إجمالي المهام</h3>
            <strong><?= $totalTasks ?></strong>
        </div>

        <div class="stat-card">
            <h3>المهام التي لم تبدأ</h3>
            <strong><?= $pendingTasks ?></strong>
        </div>

        <div class="stat-card">
            <h3>المهام قيد التنفيذ</h3>
            <strong><?= $progressTasks ?></strong>
        </div>

        <div class="stat-card">
            <h3>المهام المكتملة</h3>
            <strong><?= $completedTasks ?></strong>
        </div>

    </div>

    <div class="section">

        <h3>إضافة مهمة جديدة</h3>

        <form method="POST">

            <input type="hidden" name="action" value="add">

            <label>عنوان المهمة</label>
            <input
                type="text"
                name="title"
                placeholder="أدخل عنوان المهمة"
                maxlength="255"
                required
            >

            <label>وصف المهمة</label>
            <textarea
                name="description"
                placeholder="أدخل تفاصيل المهمة..."
            ></textarea>

            <label>تاريخ إنجاز المهمة</label>
            <input
                type="date"
                name="due_date"
            >

            <button type="submit" class="add-button">
                إضافة المهمة
            </button>

        </form>

    </div>

    <div class="section">

        <h3>قائمة المهام الإدارية</h3>

        <?php if (empty($tasks)): ?>

            <div class="empty">
                لا توجد مهام إدارية حاليا.
            </div>

        <?php else: ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>عنوان المهمة</th>
                            <th>الوصف</th>
                            <th>تاريخ الإنجاز</th>
                            <th>الحالة</th>
                            <th>تاريخ الإضافة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($tasks as $task): ?>

                        <tr>

                            <td>
                                <?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($task['description'] ?? 'لا يوجد وصف', ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($task['due_date'] ?? 'غير محدد', ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <span class="badge <?= htmlspecialchars(taskStatusClass($task['status'])) ?>">
                                    <?= htmlspecialchars(taskStatus($task['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>

                            <td>
                                <?= htmlspecialchars($task['created_at'], ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>

                                <form method="POST" class="update-form">

                                    <input type="hidden" name="action" value="update">

                                    <input
                                        type="hidden"
                                        name="task_id"
                                        value="<?= (int) $task['id'] ?>"
                                    >

                                    <select name="status" required>

                                        <option value="pending"
                                            <?= $task['status'] === 'pending' ? 'selected' : '' ?>>
                                            لم تبدأ
                                        </option>

                                        <option value="in_progress"
                                            <?= $task['status'] === 'in_progress' ? 'selected' : '' ?>>
                                            قيد التنفيذ
                                        </option>

                                        <option value="completed"
                                            <?= $task['status'] === 'completed' ? 'selected' : '' ?>>
                                            مكتملة
                                        </option>

                                    </select>

                                    <button type="submit" class="update-button">
                                        تحديث الحالة
                                    </button>

                                </form>

                                <form method="POST"
                                      onsubmit="return confirm('هل أنت متأكد من حذف هذه المهمة؟');">

                                    <input type="hidden" name="action" value="delete">

                                    <input
                                        type="hidden"
                                        name="task_id"
                                        value="<?= (int) $task['id'] ?>"
                                    >

                                    <button type="submit" class="delete-button">
                                        حذف المهمة
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
