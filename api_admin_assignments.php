<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';

$db = get_db();
$action = $_GET['action'] ?? '';

// Helpers
function getAbsoluteSem($semesterStr) {
    if (!$semesterStr) return 1;
    $str = strtolower(trim((string)$semesterStr));
    if (preg_match('/(\d+)(?:st|nd|rd|th)?\s+year/i', $str, $yMatch) && preg_match('/semester\s+(\d+)/i', $str, $sMatch)) {
        return (intval($yMatch[1]) - 1) * 2 + intval($sMatch[1]);
    }
    if (preg_match('/(\d+)(?:st|nd|rd|th)?\s+semester/i', $str, $m)) {
        return intval($m[1]);
    }
    return intval($str) ?: 1;
}

if ($action === 'get_dashboard_summary') {
    $reqDept = trim(strtolower($_GET['dept'] ?? 'ALL'));
    $reqYear = isset($_GET['year']) && strtoupper($_GET['year']) === 'ALL' ? 'ALL' : intval($_GET['year'] ?? 1);
    $reqSem = isset($_GET['sem']) && strtoupper($_GET['sem']) === 'ALL' ? 'ALL' : intval($_GET['sem'] ?? 1);
    $reqDiv = isset($_GET['div']) && strtoupper($_GET['div']) === 'ALL' ? 'ALL' : trim(strtoupper($_GET['div'] ?? 'A'));
    $search = trim(strtolower($_GET['search'] ?? ''));

    $absoluteSem = ($reqYear - 1) * 2 + $reqSem;

    // Filter Students
    $filteredStudents = [];
    $studentIds = [];
    foreach ($db['students'] ?? [] as $s) {
        $sDeptRaw = $s['department'] ?? (isset($s['dept']) ? explode(' - ', $s['dept'])[0] : 'it');
        $sDept = strtolower(trim($sDeptRaw));
        $sDiv = strtoupper($s['division'] ?? '');
        
        // Extract division from dept string if necessary (e.g., "IT - Div A")
        if (empty($sDiv) && preg_match('/Div\s*([A-Z])/i', $s['dept'] ?? '', $m)) {
            $sDiv = strtoupper($m[1]);
        }
        if (empty($sDiv)) $sDiv = 'A';

        if ($search !== '') {
            $q = strtolower(trim(str_ireplace('zprn', '', $search)));
            $q_original = strtolower(trim($search));
            $prn = strtolower($s['prn'] ?? '');
            $id = strtolower($s['id'] ?? '');
            $name = strtolower($s['name'] ?? '');
            $email = strtolower($s['email'] ?? '');
            if (strpos($prn, $q) !== false || strpos($id, $q) !== false || strpos($name, $q_original) !== false || strpos($email, $q_original) !== false) {
                $filteredStudents[] = $s;
                $studentIds[] = $s['id'] ?? $s['username'] ?? $s['prn'];
            }
        } else {
            $sSemVal = getAbsoluteSem($s['semester'] ?? '');
            if ($reqYear === 'ALL' || $reqSem === 'ALL') {
                $matchSem = true;
            } else {
                $matchSem = ($sSemVal === $absoluteSem);
            }
            $matchDiv = ($reqDiv === 'ALL' || $sDiv === $reqDiv);
            $matchDept = true;
            if ($reqDept !== 'all') {
                $matchDept = ($sDept === $reqDept || strpos($sDept, $reqDept) !== false);
            }
            if ($matchSem && $matchDiv && $matchDept) {
                $filteredStudents[] = $s;
                $studentIds[] = $s['id'] ?? $s['username'] ?? $s['prn'];
            }
        }
    }

    $totalStudents = count($filteredStudents);

    // Get all submissions for these students
    $studentSubs = [];
    foreach ($db['assignment_submissions'] ?? [] as $sub) {
        if (in_array($sub['student_id'], $studentIds) || in_array($sub['student_name'], array_column($filteredStudents, 'name'))) {
            $studentSubs[] = $sub;
        }
    }

    // Determine Required Subject Assignments for this cohort
    $requiredAssignments = [];
    $subjectNames = [];
    foreach ($db['subject_assignments'] ?? [] as $sa) {
        $saDept = strtolower($sa['department'] ?? '');
        $saDiv = strtoupper($sa['division'] ?? '');
        
        $matchDept = ($saDept === '' || $saDept === 'all');
        if (!$matchDept && $reqDept !== 'all') {
            $matchDept = (strpos($reqDept, $saDept) !== false || strpos($saDept, $reqDept) !== false);
            if (!$matchDept) {
                if ((strpos($reqDept, 'it') !== false || strpos($reqDept, 'information')) && (strpos($saDept, 'it') !== false || strpos($saDept, 'information'))) $matchDept = true;
                if ((strpos($reqDept, 'ce') !== false || strpos($reqDept, 'computer')) && (strpos($saDept, 'ce') !== false || strpos($saDept, 'computer'))) $matchDept = true;
            }
        }

        $matchDiv = ($saDiv === '' || $saDiv === 'ALL' || $saDiv === $reqDiv);
        
        // Also check if search matched some students, and if so, find assignments by their exact properties
        if ($search !== '') {
            $requiredAssignments[] = $sa; // Simplified for search mode, show all applicable to search results
        } elseif ($matchDept && $matchDiv) {
            $requiredAssignments[] = $sa;
            $subjName = $sa['subject_name'] ?? 'Unknown Subject';
            if (!in_array($subjName, $subjectNames)) {
                $subjectNames[] = $subjName;
            }
        }
    }
    
    if ($search !== '') {
        // Find subject names based on what search results returned
        foreach ($studentSubs as $s) {
            $sa = array_filter($requiredAssignments, fn($a) => $a['id'] == $s['subject_assignment_id']);
            if (!empty($sa)) {
                $sa = reset($sa);
                $subjName = $sa['subject_name'] ?? 'Unknown Subject';
                if (!in_array($subjName, $subjectNames)) {
                    $subjectNames[] = $subjName;
                }
            }
        }
    }

    $totalSubjects = count($subjectNames);
    $totalAssignmentsPublished = count($requiredAssignments);

    $totalExpected = $totalStudents * $totalAssignmentsPublished;
    
    // Group required assignments by subject
    $subjectSummary = [];
    foreach ($subjectNames as $subjName) {
        $subjectSummary[$subjName] = [
            'subject_name' => $subjName,
            'faculty_name' => 'Various Faculty', 
            'total_assignments' => 0,
            'expected_submissions' => 0,
            'submitted' => 0,
            'pending' => 0,
            'total_score' => 0,
            'graded_count' => 0,
            'assignments' => []
        ];
    }

    // Faculty map
    $facultyMap = [];
    foreach ($db['faculty'] ?? [] as $f) {
        $fSubjs = explode(',', $f['subjects'] ?? '');
        foreach ($fSubjs as $fs) {
            $facultyMap[trim($fs)] = $f['name'];
        }
    }

    $totalSubmitted = 0;
    $totalPending = 0;

    foreach ($requiredAssignments as $sa) {
        $subjName = $sa['subject_name'] ?? 'Unknown Subject';
        if (!in_array($subjName, $subjectNames)) continue;

        if (isset($facultyMap[$subjName])) {
            $subjectSummary[$subjName]['faculty_name'] = $facultyMap[$subjName];
        }

        $assignTitle = !empty($sa['assignment_title']) ? $sa['assignment_title'] : (!empty($sa['unit']) ? 'Unit ' . $sa['unit'] : 'Assignment');
        
        $aData = [
            'id' => $sa['id'],
            'title' => $assignTitle,
            'submitted' => 0,
            'pending' => $totalStudents,
            'total_score' => 0,
            'graded_count' => 0
        ];

        foreach ($filteredStudents as $s) {
            $sid = $s['id'] ?? $s['username'] ?? $s['prn'];
            $sname = $s['name'] ?? '';
            $foundSub = false;
            
            foreach ($studentSubs as $sub) {
                if (($sub['student_id'] == $sid || $sub['student_name'] === $sname) && $sub['subject_assignment_id'] == $sa['id']) {
                    $foundSub = true;
                    $aData['submitted']++;
                    $aData['pending']--;
                    $totalSubmitted++;
                    
                    if ($sub['marks'] !== 'Pending') {
                        $m = intval($sub['marks']);
                        if ($m >= 0) {
                            $aData['total_score'] += $m;
                            $aData['graded_count']++;
                        }
                    }
                    break;
                }
            }
            if (!$foundSub) {
                $totalPending++;
            }
        }

        $aData['completion'] = $totalStudents > 0 ? round(($aData['submitted'] / $totalStudents) * 100) : 0;

        $subjectSummary[$subjName]['total_assignments']++;
        $subjectSummary[$subjName]['expected_submissions'] += $totalStudents;
        $subjectSummary[$subjName]['submitted'] += $aData['submitted'];
        $subjectSummary[$subjName]['pending'] += $aData['pending'];
        $subjectSummary[$subjName]['total_score'] += $aData['total_score'];
        $subjectSummary[$subjName]['graded_count'] += $aData['graded_count'];
        $subjectSummary[$subjName]['assignments'][] = $aData;
    }

    $subjectArray = [];
    $highestSubj = ['name' => 'N/A', 'comp' => -1];
    $lowestSubj = ['name' => 'N/A', 'comp' => 101];
    $totalClassScore = 0;
    $totalClassGraded = 0;

    foreach ($subjectSummary as $subjName => $data) {
        $comp = $data['expected_submissions'] > 0 ? round(($data['submitted'] / $data['expected_submissions']) * 100) : 0;
        $data['completion'] = $comp;
        $data['avg_score'] = $data['graded_count'] > 0 ? round(($data['total_score'] / $data['graded_count']) * 10) : 0; // % assuming out of 10
        
        $totalClassScore += $data['total_score'];
        $totalClassGraded += $data['graded_count'];

        if ($comp >= 80) $data['status'] = 'Excellent';
        elseif ($comp >= 60) $data['status'] = 'Good';
        elseif ($comp >= 40) $data['status'] = 'Average';
        else $data['status'] = 'Needs Attention';

        if ($comp > $highestSubj['comp']) { $highestSubj = ['name' => $subjName, 'comp' => $comp]; }
        if ($comp < $lowestSubj['comp'] && $data['expected_submissions'] > 0) { $lowestSubj = ['name' => $subjName, 'comp' => $comp]; }

        $subjectArray[] = $data;
    }
    
    if ($lowestSubj['comp'] === 101) $lowestSubj = ['name' => 'N/A', 'comp' => 0];

    $classAvg = $totalClassGraded > 0 ? round(($totalClassScore / $totalClassGraded) * 10) : 0; // percentage

    // Faculty completion rates
    $facRates = [];
    foreach ($subjectArray as $s) {
        if (!isset($facRates[$s['faculty_name']])) {
            $facRates[$s['faculty_name']] = ['sub' => 0, 'exp' => 0];
        }
        $facRates[$s['faculty_name']]['sub'] += $s['submitted'];
        $facRates[$s['faculty_name']]['exp'] += $s['expected_submissions'];
    }
    $topFac = 'N/A';
    $topFacRate = -1;
    foreach ($facRates as $fname => $counts) {
        $rate = $counts['exp'] > 0 ? ($counts['sub'] / $counts['exp']) : 0;
        if ($rate > $topFacRate && $counts['exp'] > 0) {
            $topFacRate = $rate;
            $topFac = $fname;
        }
    }

    // Recent activity dummy implementation
    $recentActivity = array_slice($db['recent_activity'] ?? [], 0, 10);
    
    // Recent Assignments Table Data
    $recentAssignments = [];
    $allAss = array_reverse($requiredAssignments);
    foreach (array_slice($allAss, 0, 5) as $sa) {
        $subjName = $sa['subject_name'] ?? 'Unknown';
        $fac = $facultyMap[$subjName] ?? 'Various';
        $title = !empty($sa['assignment_title']) ? $sa['assignment_title'] : (!empty($sa['unit']) ? 'Unit ' . $sa['unit'] : 'Assignment');
        
        $subCount = 0;
        foreach ($studentSubs as $sub) {
            if ($sub['subject_assignment_id'] == $sa['id']) $subCount++;
        }
        
        $recentAssignments[] = [
            'id' => $sa['id'],
            'title' => $title,
            'subject' => $subjName,
            'faculty' => $fac,
            'due_date' => $sa['due_date'] ?? 'N/A',
            'submitted' => $subCount,
            'pending' => $totalStudents - $subCount,
            'total_students' => $totalStudents,
            'completion' => $totalStudents > 0 ? round(($subCount / $totalStudents) * 100) : 0
        ];
    }
    $matchedStudentsData = [];
    $allStudentsData = [];
    foreach ($filteredStudents as $fs) {
        $studentObj = [
            'id' => $fs['id'] ?? $fs['username'] ?? $fs['prn'] ?? 'N/A',
            'name' => $fs['name'] ?? 'Unknown',
            'prn' => $fs['prn'] ?? $fs['id'] ?? 'N/A',
            'year' => isset($fs['semester']) ? ceil(getAbsoluteSem($fs['semester']) / 2) : 'N/A',
            'semester' => $fs['semester'] ?? 'N/A',
            'division' => $fs['division'] ?? 'N/A',
            'department' => $fs['department'] ?? $fs['dept'] ?? 'N/A',
            'photo' => $fs['avatar'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($fs['name'] ?? 'Unknown')
        ];
        $allStudentsData[] = $studentObj;
        
        if ($search !== '') {
            $matchedStudentsData[] = $studentObj;
        }
    }

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_students' => $totalStudents,
            'total_subjects' => $totalSubjects,
            'total_assignments' => $totalAssignmentsPublished,
            'submitted' => $totalSubmitted,
            'pending' => $totalPending,
            'expected' => $totalExpected
        ],
        'subject_summary' => array_values($subjectArray),
        'analytics' => [
            'class_average' => $classAvg,
            'highest_subject' => $highestSubj['name'],
            'lowest_subject' => $lowestSubj['name'],
            'top_faculty' => $topFac
        ],
        'recent_activity' => $recentActivity,
        'recent_assignments' => $recentAssignments,
        'matched_students' => $matchedStudentsData,
        'all_students' => $allStudentsData
    ]);
    exit;
}

if ($action === 'get_assignment_students') {
    $saId = $_GET['sa_id'] ?? '';
    if (!$saId) {
        echo json_encode(['success' => false, 'error' => 'Missing sa_id']);
        exit;
    }

    $reqDept = trim(strtolower($_GET['dept'] ?? 'ALL'));
    $reqYear = intval($_GET['year'] ?? 1);
    $reqSem = intval($_GET['sem'] ?? 1);
    $reqDiv = trim(strtoupper($_GET['div'] ?? 'A'));
    $search = trim(strtolower($_GET['search'] ?? ''));
    $absoluteSem = ($reqYear - 1) * 2 + $reqSem;

    // Filter Students for this cohort
    $cohortStudents = [];
    foreach ($db['students'] ?? [] as $s) {
        $sDept = strtolower($s['department'] ?? $s['dept'] ?? 'it');
        $sDiv = strtoupper($s['division'] ?? 'A');
        
        if (empty($s['division']) && preg_match('/Div\s*([A-Z])/i', $sDept, $m)) {
            $sDiv = strtoupper($m[1]);
        }

        $sSemVal = getAbsoluteSem($s['semester'] ?? '');
        $matchSem = ($sSemVal === $absoluteSem);
        $matchDiv = ($sDiv === $reqDiv);
        $matchDept = true;
        if ($reqDept !== 'all') {
            $matchDept = ($sDept === $reqDept || strpos($sDept, $reqDept) !== false);
        }
        
        if ($search !== '') {
            $q = $search;
            $prn = strtolower($s['prn'] ?? '');
            $id = strtolower($s['id'] ?? '');
            $name = strtolower($s['name'] ?? '');
            $email = strtolower($s['email'] ?? '');
            if (strpos($prn, $q) !== false || strpos($id, $q) !== false || strpos($name, $q) !== false || strpos($email, $q) !== false) {
                $cohortStudents[] = $s;
            }
        } elseif ($matchSem && $matchDiv && $matchDept) {
            $cohortStudents[] = $s;
        }
    }

    // Get all submissions for this specific assignment
    $assignmentSubs = [];
    foreach ($db['assignment_submissions'] ?? [] as $sub) {
        if ($sub['subject_assignment_id'] == $saId) {
            $assignmentSubs[$sub['student_id'] ?? ''] = $sub;
            // Map by name as well due to some weirdness in db
            $assignmentSubs[$sub['student_name'] ?? ''] = $sub;
        }
    }

    $studentsData = [];
    foreach ($cohortStudents as $s) {
        $sid = $s['id'] ?? $s['username'] ?? $s['prn'];
        $sname = $s['name'] ?? '';
        
        $sub = $assignmentSubs[$sid] ?? $assignmentSubs[$sname] ?? null;
        
        $status = 'Not Uploaded';
        $marks = '-';
        $percentage = 0;
        $submittedAt = '-';
        $isLate = false; 

        if ($sub) {
            if ($sub['marks'] === 'Pending') {
                $status = 'Pending'; // This implies Not Evaluated but submitted
            } else {
                $status = 'Submitted'; // Graded
                $marks = $sub['marks'];
                $m = intval($marks);
                $percentage = ($m > 0) ? ($m / 10) * 100 : 0; 
            }
            $submittedAt = $sub['submitted_at'] ?? '-';
        }

        $studentsData[] = [
            'photo' => $s['avatar'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($s['name']),
            'roll' => $s['prn'] ?? $sid,
            'name' => $s['name'],
            'status' => $status,
            'marks' => $marks,
            'percentage' => $percentage,
            'submitted_at' => $submittedAt,
            'is_late' => $isLate
        ];
    }

    echo json_encode([
        'success' => true,
        'students' => $studentsData
    ]);
    exit;
}

if ($action === 'export_csv') {
    $data = $_POST['data'] ?? '[]';
    $decoded = json_decode($data, true) ?? [];
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="report.csv"');
    $output = fopen('php://output', 'w');
    if (!empty($decoded)) {
        fputcsv($output, array_keys($decoded[0]));
        foreach ($decoded as $row) {
            fputcsv($output, array_values($row));
        }
    }
    fclose($output);
    exit;
}

if ($action === 'get_student_assignment_report') {
    $reqDept = trim(strtolower($_GET['dept'] ?? 'ALL'));
    $reqYear = intval($_GET['year'] ?? 1);
    $reqSem = intval($_GET['sem'] ?? 1);
    $reqDiv = trim(strtoupper($_GET['div'] ?? 'A'));
    $search = trim(strtolower($_GET['search'] ?? ''));
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, intval($_GET['limit'] ?? 10));

    $absoluteSem = ($reqYear - 1) * 2 + $reqSem;

    // Filter Students
    $filteredStudents = [];
    foreach ($db['students'] ?? [] as $s) {
        $sDept = strtolower($s['department'] ?? $s['dept'] ?? 'it');
        $sDiv = strtoupper($s['division'] ?? 'A');
        
        if (empty($s['division']) && preg_match('/Div\s*([A-Z])/i', $sDept, $m)) {
            $sDiv = strtoupper($m[1]);
        }

        $matchDept = ($reqDept === 'all' || $sDept === $reqDept || strpos($sDept, $reqDept) !== false);
        $sSemVal = getAbsoluteSem($s['semester'] ?? '');
        $matchSem = ($sSemVal === $absoluteSem);
        $matchDiv = ($sDiv === $reqDiv);

        if ($search !== '') {
            $q = $search;
            $prn = strtolower($s['prn'] ?? '');
            $id = strtolower($s['id'] ?? '');
            $name = strtolower($s['name'] ?? '');
            if (strpos($prn, $q) !== false || strpos($id, $q) !== false || strpos($name, $q) !== false) {
                $filteredStudents[] = $s;
            }
        } else {
            if ($matchSem && $matchDiv && $matchDept) {
                $filteredStudents[] = $s;
            }
        }
    }

    $totalRecords = count($filteredStudents);
    $offset = ($page - 1) * $limit;
    $paginatedStudents = array_slice($filteredStudents, $offset, $limit);

    // Get assignments required for this cohort
    $requiredAssignments = [];
    foreach ($db['subject_assignments'] ?? [] as $sa) {
        $saDept = strtolower($sa['department'] ?? '');
        $saDiv = strtoupper($sa['division'] ?? '');
        
        $matchDept = ($saDept === '' || $saDept === 'all');
        if (!$matchDept && $reqDept !== 'all') {
            $matchDept = (strpos($reqDept, $saDept) !== false || strpos($saDept, $reqDept) !== false);
            if (!$matchDept) {
                if ((strpos($reqDept, 'it') !== false || strpos($reqDept, 'information')) && (strpos($saDept, 'it') !== false || strpos($saDept, 'information'))) $matchDept = true;
                if ((strpos($reqDept, 'ce') !== false || strpos($reqDept, 'computer')) && (strpos($saDept, 'ce') !== false || strpos($saDept, 'computer'))) $matchDept = true;
            }
        }

        $matchDiv = ($saDiv === '' || $saDiv === 'ALL' || $saDiv === $reqDiv);
        
        if ($matchDept && $matchDiv) {
            $requiredAssignments[] = $sa;
        }
    }
    
    $totalAssignments = count($requiredAssignments);

    $studentData = [];
    foreach ($paginatedStudents as $s) {
        $sid = $s['id'] ?? $s['username'] ?? $s['prn'];
        $sname = $s['name'] ?? '';
        
        $submitted = 0;
        $gradedCount = 0;
        $totalMarks = 0;
        
        foreach ($db['assignment_submissions'] ?? [] as $sub) {
            if (($sub['student_id'] == $sid || $sub['student_name'] === $sname) && in_array($sub['subject_assignment_id'], array_column($requiredAssignments, 'id'))) {
                $submitted++;
                if ($sub['marks'] !== 'Pending' && intval($sub['marks']) >= 0) {
                    $totalMarks += intval($sub['marks']);
                    $gradedCount++;
                }
            }
        }
        
        $pending = max(0, $totalAssignments - $submitted);
        $completion = $totalAssignments > 0 ? round(($submitted / $totalAssignments) * 100) : 0;
        $avgMarks = $gradedCount > 0 ? round($totalMarks / $gradedCount, 2) : 0;
        
        $status = 'Good';
        if ($completion < 50) $status = 'Needs Attention';
        elseif ($completion >= 80) $status = 'Excellent';

        $studentData[] = [
            'id' => $sid,
            'roll' => $s['prn'] ?? $sid,
            'prn' => $s['prn'] ?? $sid,
            'name' => $s['name'],
            'division' => $s['division'] ?? 'A',
            'completion' => $completion,
            'submitted' => $submitted,
            'pending' => $pending,
            'avg_marks' => $avgMarks,
            'status' => $status
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $studentData,
        'pagination' => [
            'total' => $totalRecords,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($totalRecords / $limit)
        ]
    ]);
    exit;
}

if ($action === 'get_student_subjects') {
    $sid = $_GET['student_id'] ?? '';
    if (!$sid) { echo json_encode(['success' => false, 'error' => 'Missing student ID']); exit; }

    $student = null;
    foreach ($db['students'] ?? [] as $s) {
        if (($s['id'] ?? $s['username'] ?? $s['prn']) == $sid) {
            $student = $s;
            break;
        }
    }
    if (!$student) { echo json_encode(['success' => false, 'error' => 'Student not found']); exit; }
    
    $reqDept = trim(strtolower($_GET['dept'] ?? 'ALL'));
    $reqDiv = trim(strtoupper($_GET['div'] ?? 'A'));

    $subjects = [];
    $facultyMap = [];
    foreach ($db['faculty'] ?? [] as $f) {
        $fSubjs = explode(',', $f['subjects'] ?? '');
        foreach ($fSubjs as $fs) {
            $facultyMap[trim($fs)] = $f['name'];
        }
    }

    // Fetch subjects directly from the database based on the student's department
    global $pdo;
    $sDept = strtolower($student['department'] ?? $student['dept'] ?? 'it');
    $deptId = 1; // default to IT
    
    try {
        $stmt = $pdo->prepare("SELECT id FROM departments WHERE LOWER(name) LIKE ? OR LOWER(code) LIKE ? LIMIT 1");
        $stmt->execute(["%$sDept%", "%$sDept%"]);
        if ($row = $stmt->fetch()) {
            $deptId = $row['id'];
        }
        
        $stmt = $pdo->prepare("SELECT name FROM subjects WHERE department_id = ?");
        $stmt->execute([$deptId]);
        $dbSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($dbSubjects as $ds) {
            $subjName = $ds['name'];
            $subjects[] = [
                'name' => $subjName,
                'faculty' => $facultyMap[$subjName] ?? 'Faculty TBD'
            ];
        }
    } catch (PDOException $e) {
        // Fallback
    }

    echo json_encode([
        'success' => true,
        'subjects' => $subjects
    ]);
    exit;
}

if ($action === 'get_student_subject_assignments') {
    $sid = $_GET['student_id'] ?? '';
    $subjName = $_GET['subject_name'] ?? '';
    if (!$sid || !$subjName) { echo json_encode(['success' => false, 'error' => 'Missing student ID or subject name']); exit; }

    $reqDept = trim(strtolower($_GET['dept'] ?? 'ALL'));
    $reqDiv = trim(strtoupper($_GET['div'] ?? 'A'));

    $student = null;
    foreach ($db['students'] ?? [] as $s) {
        if (($s['id'] ?? $s['username'] ?? $s['prn']) == $sid) {
            $student = $s;
            break;
        }
    }

    $facultyMap = [];
    foreach ($db['faculty'] ?? [] as $f) {
        $fSubjs = explode(',', $f['subjects'] ?? '');
        foreach ($fSubjs as $fs) {
            $facultyMap[trim($fs)] = $f['name'];
        }
    }

    $assignments = [];
    foreach ($db['subject_assignments'] ?? [] as $sa) {
        if (($sa['subject_name'] ?? '') === $subjName) {
            $saDept = strtolower($sa['department'] ?? '');
            $saDiv = strtoupper($sa['division'] ?? '');
            $matchDept = ($saDept === '' || $saDept === 'all');
            if (!$matchDept && $reqDept !== 'all') {
                $matchDept = (strpos($reqDept, $saDept) !== false || strpos($saDept, $reqDept) !== false);
                if (!$matchDept) {
                    if ((strpos($reqDept, 'it') !== false || strpos($reqDept, 'information')) && (strpos($saDept, 'it') !== false || strpos($saDept, 'information'))) $matchDept = true;
                    if ((strpos($reqDept, 'ce') !== false || strpos($reqDept, 'computer')) && (strpos($saDept, 'ce') !== false || strpos($saDept, 'computer'))) $matchDept = true;
                }
            }
            $matchDiv = ($saDiv === '' || $saDiv === 'ALL' || $saDiv === $reqDiv);
            
            if ($matchDept && $matchDiv) {
                $sname = $student['name'] ?? '';
                $submission = null;
                foreach ($db['assignment_submissions'] ?? [] as $sub) {
                    if (($sub['student_id'] == $sid || $sub['student_name'] === $sname) && $sub['subject_assignment_id'] == $sa['id']) {
                        $submission = $sub;
                        break;
                    }
                }

                $title = !empty($sa['assignment_title']) ? $sa['assignment_title'] : (!empty($sa['unit']) ? 'Unit ' . $sa['unit'] : 'Assignment');
                
                $marks = '-';
                $percent = '-';
                $status = 'Pending';
                $subDate = '-';
                $remarks = $submission['feedback'] ?? '-';
                
                if ($submission) {
                    $status = 'Submitted';
                    $subDate = $submission['submitted_at'] ?? '-';
                    if ($submission['marks'] !== 'Pending') {
                        $marks = $submission['marks'];
                        $m = intval($marks);
                        $percent = ($m >= 0) ? round(($m / 10) * 100) . '%' : '-';
                    } else {
                        $status = 'Pending Evaluation';
                    }
                }

                $assignments[] = [
                    'id' => $sa['id'],
                    'title' => $title,
                    'faculty' => $facultyMap[$subjName] ?? 'Various Faculty',
                    'status' => $status,
                    'marks' => $marks,
                    'total_marks' => 10,
                    'percentage' => $percent,
                    'submission_date' => $subDate,
                    'remarks' => $remarks
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'assignments' => $assignments]);
    exit;
}

if ($action === 'export_student_assignment_report') {
    $reqDept = trim(strtolower($_GET['dept'] ?? 'ALL'));
    $reqYear = isset($_GET['year']) && strtoupper($_GET['year']) === 'ALL' ? 'ALL' : intval($_GET['year'] ?? 1);
    $reqSem = isset($_GET['sem']) && strtoupper($_GET['sem']) === 'ALL' ? 'ALL' : intval($_GET['sem'] ?? 1);
    $reqDiv = isset($_GET['div']) && strtoupper($_GET['div']) === 'ALL' ? 'ALL' : trim(strtoupper($_GET['div'] ?? 'A'));
    $search = trim(strtolower($_GET['search'] ?? ''));

    $absoluteSem = ($reqYear - 1) * 2 + $reqSem;

    $filteredStudents = [];
    foreach ($db['students'] ?? [] as $s) {
        $sDept = strtolower($s['department'] ?? $s['dept'] ?? 'it');
        $sDiv = strtoupper($s['division'] ?? 'A');
        
        if (empty($s['division']) && preg_match('/Div\s*([A-Z])/i', $sDept, $m)) {
            $sDiv = strtoupper($m[1]);
        }

        $matchDept = ($reqDept === 'all' || $sDept === $reqDept || strpos($sDept, $reqDept) !== false);
        $sSemVal = getAbsoluteSem($s['semester'] ?? '');
        if ($reqYear === 'ALL' || $reqSem === 'ALL') {
            $matchSem = true;
        } else {
            $matchSem = ($sSemVal === $absoluteSem);
        }
        $matchDiv = ($reqDiv === 'ALL' || $sDiv === $reqDiv);

        if ($search !== '') {
            $q = $search;
            $prn = strtolower($s['prn'] ?? '');
            $id = strtolower($s['id'] ?? '');
            $name = strtolower($s['name'] ?? '');
            if (strpos($prn, $q) !== false || strpos($id, $q) !== false || strpos($name, $q) !== false) {
                $filteredStudents[] = $s;
            }
        } else {
            if ($matchSem && $matchDiv && $matchDept) {
                $filteredStudents[] = $s;
            }
        }
    }

    $requiredAssignments = [];
    foreach ($db['subject_assignments'] ?? [] as $sa) {
        $saDept = strtolower($sa['department'] ?? '');
        $saDiv = strtoupper($sa['division'] ?? '');
        
        $matchDept = ($saDept === '' || $saDept === 'all');
        if (!$matchDept && $reqDept !== 'all') {
            $matchDept = (strpos($reqDept, $saDept) !== false || strpos($saDept, $reqDept) !== false);
            if (!$matchDept) {
                if ((strpos($reqDept, 'it') !== false || strpos($reqDept, 'information')) && (strpos($saDept, 'it') !== false || strpos($saDept, 'information'))) $matchDept = true;
                if ((strpos($reqDept, 'ce') !== false || strpos($reqDept, 'computer')) && (strpos($saDept, 'ce') !== false || strpos($saDept, 'computer'))) $matchDept = true;
            }
        }
        $matchDiv = ($saDiv === '' || $saDiv === 'ALL' || $saDiv === $reqDiv);
        
        if ($matchDept && $matchDiv) {
            $requiredAssignments[] = $sa;
        }
    }

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Student_Assignment_Report.xls"');
    
    // Generate HTML for Excel styling
    $html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    $html .= '<head><meta charset="UTF-8"><style>';
    $html .= 'table { border-collapse: collapse; font-family: Calibri, sans-serif; }';
    $html .= 'th, td { border: 1px solid #d1d5db; padding: 5px; text-align: center; vertical-align: middle; }';
    $html .= '.header-dark { background-color: #0f172a; color: white; font-weight: bold; }';
    $html .= '.title-row { font-size: 16pt; }';
    $html .= '.section-header { color: white; font-weight: bold; font-size: 11pt; text-align: left; padding: 8px !important; }';
    $html .= '.section-badge { display: inline-block; width: 20px; height: 20px; line-height: 20px; text-align: center; color: white; margin-right: 8px; font-weight: bold; }';
    $html .= '.filter-header { background-color: #f1f5f9; font-weight: bold; font-size: 10pt; }';
    $html .= '.grade-a { background-color: #dcfce7; color: #166534; font-weight: bold; }';
    $html .= '.grade-b { background-color: #fef3c7; color: #92400e; font-weight: bold; }';
    $html .= '.grade-c { background-color: #ffedd5; color: #c2410c; font-weight: bold; }';
    $html .= '.grade-f { background-color: #fee2e2; color: #991b1b; font-weight: bold; }';
    $html .= '</style></head><body>';
    $html .= '<table>';
    
    // Unique subjects logic
    $uniqueSubjects = [];
    $subjectFaculty = [];
    foreach ($requiredAssignments as $sa) {
        $subjName = $sa['subject_name'] ?? 'Unknown Subject';
        if (!in_array($subjName, $uniqueSubjects)) {
            $uniqueSubjects[] = $subjName;
            $facName = 'Various Faculty';
            foreach ($db['faculty'] ?? [] as $f) {
                $fSubjs = explode(',', $f['subjects'] ?? '');
                if (in_array($subjName, array_map('trim', $fSubjs))) {
                    $facName = $f['name'];
                    break;
                }
            }
            $subjectFaculty[$subjName] = $facName;
        }
    }

    $cols = 17 + count($uniqueSubjects); // For flattened table headers

    // Title
    $html .= '<tr><td colspan="' . $cols . '" class="header-dark title-row" style="padding: 10px;">STUDENT ASSIGNMENT REPORT</td></tr>';
    
    // Filters Header
    $html .= '<tr class="filter-header" style="background-color: #e2e8f0;">';
    $html .= '<td colspan="3">Department</td>';
    $html .= '<td colspan="2">Academic Year</td>';
    $html .= '<td colspan="2">Semester</td>';
    $html .= '<td colspan="2">Division</td>';
    $html .= '<td colspan="' . ($cols - 9) . '">Generated On</td>';
    $html .= '</tr>';
    
    // Filters Data
    $html .= '<tr>';
    $html .= '<td colspan="3">' . ucwords(strtolower($reqDept === 'all' ? 'Information Technology' : $reqDept)) . '</td>';
    $html .= '<td colspan="2">2025-26</td>'; // format similar to image
    $html .= '<td colspan="2">Semester ' . $reqSem . '</td>';
    $html .= '<td colspan="2">Div ' . $reqDiv . '</td>';
    $html .= '<td colspan="' . ($cols - 9) . '">' . date('d-M-Y h:i A') . '</td>';
    $html .= '</tr>';
    $html .= '<tr><td colspan="' . $cols . '"></td></tr>';

    // Pre-calculate data for all students to avoid repeated loops
    $studentData = [];
    
    foreach ($filteredStudents as $s) {
        $sid = $s['id'] ?? $s['username'] ?? $s['prn'];
        $sname = $s['name'] ?? '';
        $sprn = $s['prn'] ?? $sid;
        $sroll = $s['roll_no'] ?? '-';
        if ($sroll === '-') $sroll = $sid;

        $totalAssign = count($requiredAssignments);
        $submittedCount = 0;
        $totalMarks = 0;
        $gradedCount = 0;
        
        $assignmentsDetailed = [];
        $subjectScores = []; // to track pivot scores

        foreach ($uniqueSubjects as $usubj) {
            $subjectScores[$usubj] = ['total' => 0, 'submitted' => 0, 'marks' => 0, 'graded' => 0];
        }
        
        // Count assignment index per subject for "Assignment 1", "Assignment 2" formatting
        $subjAssignIndex = [];

        if ($totalAssign > 0) {
            foreach ($requiredAssignments as $sa) {
                $subjName = $sa['subject_name'] ?? 'Unknown Subject';
                if (!isset($subjAssignIndex[$subjName])) $subjAssignIndex[$subjName] = 1;
                else $subjAssignIndex[$subjName]++;
                
                $assignTitle = 'Assignment ' . $subjAssignIndex[$subjName];
                
                $subjectScores[$subjName]['total']++;

                $submission = null;
                foreach ($db['assignment_submissions'] ?? [] as $sub) {
                    if (($sub['student_id'] == $sid || $sub['student_name'] === $sname) && $sub['subject_assignment_id'] == $sa['id']) {
                        $submission = $sub;
                        $submittedCount++;
                        $subjectScores[$subjName]['submitted']++;
                        
                        if (isset($sub['marks']) && $sub['marks'] !== 'Pending') {
                            $m = intval($sub['marks']);
                            $totalMarks += $m;
                            $gradedCount++;
                            $subjectScores[$subjName]['marks'] += $m;
                            $subjectScores[$subjName]['graded']++;
                        }
                        break;
                    }
                }
                
                $marksObtained = '-';
                $percentObtained = '-';
                $status = 'Pending';
                $subDate = '-';
                
                if ($submission) {
                    $status = 'Submitted';
                    $subDate = $submission['submitted_at'] ?? '-';
                    if (isset($submission['marks']) && $submission['marks'] !== 'Pending') {
                        $marksObtained = intval($submission['marks']); 
                        $percentObtained = number_format(($marksObtained / 10) * 100, 2) . '%';
                    } else {
                        $status = 'Pending Eval';
                    }
                }

                $assignmentsDetailed[] = [
                    'subjName' => $subjName,
                    'assignTitle' => $assignTitle,
                    'marksObtained' => $marksObtained,
                    'totalMarks' => 10,
                    'percentage' => $percentObtained,
                    'status' => $status,
                    'subDate' => $subDate,
                    'faculty' => $subjectFaculty[$subjName] ?? '-'
                ];
            }
        }
        
        $pendingCount = $totalAssign - $submittedCount;
        
        $avgPercentNum = 0;
        $avgPercent = '-';
        $grade = '-';
        $comments = '-';
        $gradeClass = '';
        
        // pivot calculations first to get mean of subjects
        $pivotCols = [];
        $sumPercentages = 0;
        $validSubjects = 0;
        foreach ($uniqueSubjects as $usubj) {
            $pMarks = $subjectScores[$usubj]['marks'];
            $pTotal = $subjectScores[$usubj]['total'];
            if ($pTotal > 0) {
                $validSubjects++;
                $pAvg = ($pMarks / ($pTotal * 10)) * 100;
                $pivotCols[$usubj] = number_format($pAvg, 2) . '%';
                $sumPercentages += $pAvg;
            } else {
                $pivotCols[$usubj] = '-';
            }
        }

        if ($validSubjects > 0) {
            $avgPercentNum = $sumPercentages / $validSubjects;
            $avgPercent = number_format($avgPercentNum, 2) . '%';
            if ($avgPercentNum >= 90) { $grade = 'A+'; $gradeClass = 'grade-a'; }
            elseif ($avgPercentNum >= 80) { $grade = 'A'; $gradeClass = 'grade-a'; }
            elseif ($avgPercentNum >= 70) { $grade = 'B+'; $gradeClass = 'grade-b'; }
            elseif ($avgPercentNum >= 60) { $grade = 'B'; $gradeClass = 'grade-b'; }
            elseif ($avgPercentNum >= 50) { $grade = 'C'; $gradeClass = 'grade-c'; }
            elseif ($avgPercentNum >= 40) { $grade = 'D'; $gradeClass = 'grade-c'; }
            else { $grade = 'F'; $gradeClass = 'grade-f'; }
            
            // New Faculty Comments Logic based on submission completion
            if ($submittedCount === 0) {
                $comments = 'Critical attention needed';
            } elseif ($submittedCount === $totalAssign) {
                $comments = 'Excellent work, all assignments submitted';
            } else {
                $completionRatio = $submittedCount / $totalAssign;
                if ($completionRatio < 0.5) {
                    $comments = 'Needs immediate improvement';
                } else {
                    $comments = 'Good progress, complete remaining assignment' . ($pendingCount > 1 ? 's' : '');
                }
            }
        } else {
            $comments = 'No assignments';
        }

        $studentData[] = [
            'roll' => $sroll,
            'prn' => $sprn,
            'name' => $sname,
            'totalAssign' => $totalAssign,
            'submitted' => $submittedCount,
            'pending' => $pendingCount,
            'avgPercent' => $avgPercent,
            'avgPercentNum' => $avgPercentNum,
            'grade' => $grade,
            'gradeClass' => $gradeClass,
            'comments' => $comments,
            'detailed' => $assignmentsDetailed,
            'pivot' => $pivotCols
        ];
    }


    // =====================================
    // CONSOLIDATED FLAT DATA SHEET
    // =====================================
    $html .= '<tr class="filter-header">';
    $html .= '<th>ZPRN</th><th>Roll No</th><th style="min-width:200px;">Student Name</th>';
    $html .= '<th>Total Assignments</th><th>Submitted</th><th>Pending</th><th>Faculty Comments</th>';
    foreach ($uniqueSubjects as $subj) {
        $html .= "<th>{$subj}<br>%</th>";
    }
    $html .= '<th>Average Percentage</th><th>Overall Grade</th>';
    $html .= '<th>Subject Name</th><th>Assignment Name</th><th>Marks Obtained</th><th>Total Marks</th><th>Percentage</th><th>Submission Status</th><th>Submission Date</th><th>Faculty Name</th>';
    $html .= '</tr>';

    foreach ($studentData as $sd) {
        $subColor = $sd['submitted'] > 0 ? '#16a34a' : 'black';
        $penColor = $sd['pending'] > 0 ? '#dc2626' : 'black';
        
        if (empty($sd['detailed'])) {
            $html .= '<tr>';
            $html .= "<td>{$sd['roll']}</td>";
            $html .= "<td>{$sd['prn']}</td>";
            $html .= "<td style='text-align:left;'>{$sd['name']}</td>";
            $html .= "<td>{$sd['totalAssign']}</td>";
            $html .= "<td style='color:{$subColor}; font-weight:bold;'>{$sd['submitted']}</td>";
            $html .= "<td style='color:{$penColor}; font-weight:bold;'>{$sd['pending']}</td>";
            $html .= "<td>{$sd['comments']}</td>";
            foreach ($uniqueSubjects as $subj) {
                $html .= "<td>{$sd['pivot'][$subj]}</td>";
            }
            $html .= "<td style='background-color:#fef3c7; font-weight:bold;'>{$sd['avgPercent']}</td>";
            $html .= "<td class='{$sd['gradeClass']}'>{$sd['grade']}</td>";
            
            // Empty columns for detailed part
            $html .= "<td>-</td><td>-</td><td>-</td><td>-</td><td>-</td><td>-</td><td>-</td><td>-</td>";
            $html .= '</tr>';
        } else {
            foreach ($sd['detailed'] as $det) {
                $statusColor = 'black';
                $statusBg = 'transparent';
                if ($det['status'] === 'Submitted') { $statusColor = '#16a34a'; $statusBg = '#dcfce7'; }
                if ($det['status'] === 'Pending') { $statusColor = '#dc2626'; $statusBg = '#fee2e2'; }
                
                $html .= '<tr>';
                $html .= "<td>{$sd['roll']}</td>";
                $html .= "<td>{$sd['prn']}</td>";
                $html .= "<td style='text-align:left;'>{$sd['name']}</td>";
                $html .= "<td>{$sd['totalAssign']}</td>";
                $html .= "<td style='color:{$subColor}; font-weight:bold;'>{$sd['submitted']}</td>";
                $html .= "<td style='color:{$penColor}; font-weight:bold;'>{$sd['pending']}</td>";
                $html .= "<td>{$sd['comments']}</td>";
                
                foreach ($uniqueSubjects as $subj) {
                    $html .= "<td>{$sd['pivot'][$subj]}</td>";
                }
                $html .= "<td style='background-color:#fef3c7; font-weight:bold;'>{$sd['avgPercent']}</td>";
                $html .= "<td class='{$sd['gradeClass']}'>{$sd['grade']}</td>";
                
                $html .= "<td>{$det['subjName']}</td>";
                $html .= "<td>{$det['assignTitle']}</td>";
                $html .= "<td>{$det['marksObtained']}</td>";
                $html .= "<td>{$det['totalMarks']}</td>";
                $html .= "<td>{$det['percentage']}</td>";
                $html .= "<td style='color:{$statusColor}; background-color:{$statusBg}; font-weight:bold;'>{$det['status']}</td>";
                $html .= "<td>{$det['subDate']}</td>";
                $html .= "<td>{$det['faculty']}</td>";
                $html .= '</tr>';
            }
        }
    }

    $html .= '</table></body></html>';
    echo $html;
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
exit;
