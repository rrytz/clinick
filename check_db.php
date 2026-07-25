<?php
require_once __DIR__ . '/db.php';
$db = get_db_connection();
$res = $db->query("SELECT id, name, email, role, status FROM users ORDER BY id DESC");
echo "<table border='1'>";
echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th></tr>";
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . htmlspecialchars($row['name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['email']) . "</td>";
    echo "<td>" . htmlspecialchars($row['role']) . "</td>";
    echo "<td>" . htmlspecialchars($row['status']) . "</td>";
    echo "</tr>";
}
echo "</table>";
?>
