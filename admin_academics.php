<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: index.php');
    exit;
}

$pdo = $pdo ?? $conn ?? null;

if (!$pdo instanceof PDO) {
    die('تعذر الاتصال بقاعدة البيانات.');
}

$selectedCourse = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
$selectedStudent = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
$activeTab = $_GET['tab'] ?? 'attendance';

if (!in_array($activeTab, ['attendance', 'grades'], true)) {
    $activeTab = 'attendance';
}

try {
    // قائمة المقررات
    $coursesStmt = $pdo->query("
        SELECT id, course_code, course_name
        FROM courses
        ORDER BY course_code
    ");
    $courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

    // قائمة الطلاب
    $studentsStmt = $pdo->query("
        SELECT
            u.id,
            u.full_name,
            st.student_number
        FROM students st
        INNER JOIN users u ON u.id = st.user_id
        ORDER BY u.full_name
    ");
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // إحصائيات الحضور
    $attendanceStats = $pdo->query("
        SELECT
            COUNT(*) AS total_records,
            SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN attendance_status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN attendance_status = 'late' THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN attendance_status = 'excused' THEN 1 ELSE 0 END) AS excused_count
        FROM attendance
    ")->fetch(PDO::FETCH_ASSOC);

    // إحصائيات الدرجات
    $gradeStats = $pdo->query("
        SELECT
            COUNT(*) AS total_grades,
            COUNT(DISTINCT enrollment_id) AS graded_enrollments,
            AVG(
                CASE
                    WHEN max_score > 0
                    THEN (score / max_score) * 100
                    ELSE NULL
                END
            ) AS average_percentage
        FROM grades
    ")->fetch(PDO::FETCH_ASSOC);

    // سجلات الحضور
    $attendanceSql = "
        SELECT
            a.id,
            a.session_date,
            a.attendance_status,
            a.notes,
            u.full_name,
            st.student_number,
            c.course_code,
            c.course_name
        FROM attendance a
        INNER JOIN enrollments e ON e.id = a.enrollment_id
        INNER JOIN students st ON st.user_id = e.student_id
        INNER JOIN users u ON u.id = st.user_id
        INNER JOIN courses c ON c.id = e.course_id
        WHERE 1 = 1
    ";

    $attendanceParams = [];

    if ($selectedCourse > 0) {
        $attendanceSql .= " AND c.id = ?";
        $attendanceParams[] = $selectedCourse;
    }

    if ($selectedStudent > 0) {
        $attendanceSql .= " AND st.user_id = ?";
        $attendanceParams[] = $selectedStudent;
    }

    $attendanceSql .= " ORDER BY a.session_date DESC, a.id DESC LIMIT 300";

    $attendanceStmt = $pdo->prepare($attendanceSql);
    $attendanceStmt->execute($attendanceParams);
    $attendanceRecords = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);

    // سجلات الدرجات
    $gradesSql = "
        SELECT
            g.id,
            g.assessment_type,
            g.score,
            g.max_score,
            g.assessment_date,
            u.full_name,
            st.student_number,
            c.course_code,
            c.course_name
        FROM grades g
        INNER JOIN enrollments e ON e.id = g.enrollment_id
        INNER JOIN students st ON st.user_id = e.student_id
        INNER JOIN users u ON u.id = st.user_id
        INNER JOIN courses c ON c.id = e.course_id
        WHERE 1 = 1
    ";

    $gradesParams = [];

    if ($selectedCourse > 0) {
        $gradesSql .= " AND c.id = ?";
        $gradesParams[] = $selectedCourse;
    }

    if ($selectedStudent > 0) {
        $gradesSql .= " AND st.user_id = ?";
        $gradesParams[] = $selectedStudent;
    }

    $gradesSql .= " ORDER BY g.assessment_date DESC, g.id DESC LIMIT 300";

    $gradesStmt = $pdo->prepare($gradesSql);
    $gradesStmt->execute($gradesParams);
    $gradeRecords = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die('حدث خطأ أثناء تحميل البيانات: ' . htmlspecialchars($e->getMessage()));
}

function academicStatusLabel($status)
{
    $labels = [
        'present' => 'حاضر',
        'absent' => 'غائب',
        'late' => 'متأخر',
        'excused' => 'بعذر'
    ];

    return $labels[$status] ?? $status;
}

function academicStatusClass($status)
{
    $classes = [
        'present' => 'success',
        'absent' => 'danger',
        'late' => 'warning',
        'excused' => 'info'
    ];

    return $classes[$status] ?? 'secondary';
}

function gradeTypeLabel($type)
{
    $labels = [
        'quiz' => 'اختبار قصير',
        'midterm' => 'اختبار نصفي',
        'final' => 'اختبار نهائي',
        'assignment' => 'واجب',
        'project' => 'مشروع',
        'participation' => 'مشاركة',
        'practical' => 'عملي'
    ];

    return $labels[$type] ?? $type;
}

function h($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>متابعة الحضور والدرجات | S-UCMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Tahoma, sans-serif;
            background: #f4f6fb;
            color: #263247;
        }

        .container {
            width: 94%;
            max-width: 1400px;
            margin: 30px auto;
        }

        .header {
            background: #fff;
            padding: 25px;
            border-radius: 14px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 25px;
            color: #193b69;
        }

        .subtitle {
            color: #718096;
            margin: 0 0 18px;
        }

        .back-link {
            display: inline-block;
            color: #fff;
            background: #244d7c;
            padding: 10px 17px;
            border-radius: 7px;
            text-decoration: none;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .stat-card span {
            display: block;
            color: #718096;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .stat-card strong {
            font-size: 27px;
            color: #244d7c;
        }

        .panel {
            background: #fff;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .panel h2 {
            margin: 0 0 18px;
            color: #193b69;
            font-size: 19px;
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 15px;
            align-items: end;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            font-size: 14px;
        }

        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #dce2eb;
            border-radius: 7px;
            background: #fff;
            font-size: 14px;
        }

        button, .reset {
            border: 0;
            border-radius: 7px;
            padding: 12px 20px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }

        .filter-btn {
            background: #244d7c;
            color: white;
        }

        .reset {
            background: #e8edf4;
            color: #334155;
            text-align: center;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 12px 22px;
            border-radius: 8px;
            background: #edf1f7;
            color: #334155;
            text-decoration: none;
        }

        .tab.active {
            background: #244d7c;
            color: white;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 13px 12px;
            text-align: right;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        th {
            background: #f5f7fb;
            color: #40516b;
            white-space: nowrap;
        }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            white-space: nowrap;
        }

        .success {
            color: #167044;
            background: #e3f6ec;
        }

        .danger {
            color: #b42318;
            background: #feeceb;
        }

        .warning {
            color: #946200;
            background: #fff3cd;
        }

        .info {
            color: #176b87;
            background: #e3f5fa;
        }

        .secondary {
            color: #475569;
            background: #e9edf2;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #718096;
        }

        @media (max-width: 600px) {
            .container {
                width: 96%;
                margin: 15px auto;
            }

            .header, .panel {
                padding: 17px;
            }

            h1 {
                font-size: 21px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h1>متابعة الحضور والدرجات</h1>
        <p class="subtitle">
            متابعة سجلات حضور الطلاب ودرجاتهم الأكاديمية في جميع المقررات.
        </p>
        <a class="back-link" href="dashboard.php">العودة إلى لوحة التحكم</a>
    </div>

    <div class="stats">

        <div class="stat-card">
            <span>إجمالي سجلات الحضور</span>
            <strong><?= (int)($attendanceStats['total_records'] ?? 0) ?></strong>
        </div>

        <div class="stat-card">
            <span>عدد مرات الحضور</span>
            <strong><?= (int)($attendanceStats['present_count'] ?? 0) ?></strong>
        </div>

        <div class="stat-card">
            <span>عدد مرات الغياب</span>
            <strong><?= (int)($attendanceStats['absent_count'] ?? 0) ?></strong>
        </div>

        <div class="stat-card">
            <span>إجمالي سجلات الدرجات</span>
            <strong><?= (int)($gradeStats['total_grades'] ?? 0) ?></strong>
        </div>

        <div class="stat-card">
            <span>متوسط الدرجات</span>
            <strong>
                <?= $gradeStats['average_percentage'] !== null
                    ? number_format((float)$gradeStats['average_percentage'], 1) . '%'
                    : '—' ?>
            </strong>
        </div>

    </div>

    <div class="panel">
        <h2>البحث والتصفية</h2>

        <form method="GET" action="admin_academics.php" class="filters">

            <input type="hidden" name="tab" value="<?= h($activeTab) ?>">

            <div>
                <label>المقرر الدراسي</label>
                <select name="course_id">
                    <option value="0">جميع المقررات</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= (int)$course['id'] ?>"
                            <?= $selectedCourse === (int)$course['id'] ? 'selected' : '' ?>>
                            <?= h($course['course_code'] . ' - ' . $course['course_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>الطالب</label>
                <select name="student_id">
                    <option value="0">جميع الطلاب</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int)$student['id'] ?>"
                            <?= $selectedStudent === (int)$student['id'] ? 'selected' : '' ?>>
                            <?= h($student['full_name'] . ' - ' . $student['student_number']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="filter-btn">تطبيق التصفية</button>

            <a href="admin_academics.php?tab=<?= h($activeTab) ?>" class="reset">
                إعادة تعيين
            </a>

        </form>
    </div>

    <div class="panel">

        <div class="tabs">
            <a class="tab <?= $activeTab === 'attendance' ? 'active' : '' ?>"
               href="?tab=attendance&course_id=<?= $selectedCourse ?>&student_id=<?= $selectedStudent ?>">
                سجلات الحضور
            </a>

            <a class="tab <?= $activeTab === 'grades' ? 'active' : '' ?>"
               href="?tab=grades&course_id=<?= $selectedCourse ?>&student_id=<?= $selectedStudent ?>">
                سجلات الدرجات
            </a>
        </div>

        <?php if ($activeTab === 'attendance'): ?>

            <h2>سجلات حضور الطلاب</h2>

            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>الطالب</th>
                        <th>الرقم الجامعي</th>
                        <th>المقرر</th>
                        <th>تاريخ المحاضرة</th>
                        <th>حالة الحضور</th>
                        <th>ملاحظات</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($attendanceRecords)): ?>
                        <tr>
                            <td colspan="6" class="empty">
                                لا توجد سجلات حضور مطابقة لخيارات البحث.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attendanceRecords as $record): ?>
                            <tr>
                                <td><?= h($record['full_name']) ?></td>
                                <td><?= h($record['student_number']) ?></td>
                                <td>
                                    <?= h($record['course_code'] . ' - ' . $record['course_name']) ?>
                                </td>
                                <td><?= h($record['session_date']) ?></td>
                                <td>
                                    <span class="badge <?= h(academicStatusClass($record['attendance_status'])) ?>">
                                        <?= h(academicStatusLabel($record['attendance_status'])) ?>
                                    </span>
                                </td>
                                <td><?= h($record['notes']) ?: '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <h2>سجلات درجات الطلاب</h2>

            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>الطالب</th>
                        <th>الرقم الجامعي</th>
                        <th>المقرر</th>
                        <th>نوع التقييم</th>
                        <th>الدرجة</th>
                        <th>النسبة</th>
                        <th>تاريخ التقييم</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($gradeRecords)): ?>
                        <tr>
                            <td colspan="7" class="empty">
                                لا توجد سجلات درجات مطابقة لخيارات البحث.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($gradeRecords as $record): ?>
                            <?php
                                $percentage = (float)$record['max_score'] > 0
                                    ? ((float)$record['score'] / (float)$record['max_score']) * 100
                                    : null;
                            ?>
                            <tr>
                                <td><?= h($record['full_name']) ?></td>
                                <td><?= h($record['student_number']) ?></td>
                                <td>
                                    <?= h($record['course_code'] . ' - ' . $record['course_name']) ?>
                                </td>
                                <td><?= h(gradeTypeLabel($record['assessment_type'])) ?></td>
                                <td>
                                    <?= h($record['score']) ?> /
                                    <?= h($record['max_score']) ?>
                                </td>
                                <td>
                                    <?= $percentage !== null
                                        ? number_format($percentage, 1) . '%'
                                        : '—' ?>
                                </td>
                                <td><?= h($record['assessment_date']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
