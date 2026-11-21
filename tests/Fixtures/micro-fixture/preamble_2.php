<?php
declare(strict_types=1);

// tests/Fixtures/micro-fixture/preamble_2.php — reconstructed per
// improve_logic.md Appendix A: five classes whose go() methods share a
// 9-line preamble and differ afterwards. Siblings alter only the tail.

final class NoteListService
{
    public function go($id, $module)
    {
        $settings = get_module_settings($module);
        $db = get_module_db($module);
        if ($id === false) {
            $id = $_GET['id'];
        }
        $id = resolve_service_id($id, $module);
        $serviceInfo = get_service($id, $module);
        if ($serviceInfo === false) {
            return json_error('Invalid Order Passed', 404);
        }
        if ($serviceInfo[$settings['PREFIX'] . '_status'] !== 'active') {
            return json_error('Order is not active', 409);
        }

        $rows = $db->query("select note from order_notes where order_id = " . (int) $id);
        $out = []; while ($db->next_record()) { $out[] = $db->f("note"); }
        return json_response(["notes" => $out]);
    }
}
