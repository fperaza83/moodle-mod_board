<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die;

function coursemodule_for_board($board) {
    return get_coursemodule_from_instance('board', $board->id, $board->course, false, MUST_EXIST);
}

function get_board($id) {
    global $DB;
    return $DB->get_record('board', array('id'=>$id));
}

function get_column($id) {
    global $DB;
    return $DB->get_record('board_columns', array('id'=>$id));
}

function get_note($id) {
    global $DB;
    return $DB->get_record('board_notes', array('id'=>$id));
}
    
function context_for_board($id) {
    if (!$board = get_board($id)) {
        return null;
    }
    
    $cm = coursemodule_for_board($board);
    return context_module::instance($cm->id);
}

function context_for_column($id) {
    if (!$column = get_column($id)) {
        return null;
    }

    return context_for_board($column->boardid);
}

function require_capability_for_board_view($id) {
    $context = context_for_board($id);
    if ($context) {
        require_capability('mod/board:view', $context); 
    }
}

function require_capability_for_board($id) {
    $context = context_for_board($id);
    if ($context) {
        require_capability('mod/board:manageboard', $context); 
    }
}

function require_capability_for_column($id) {
    $context = context_for_column($id);
    if ($context) {
        require_capability('mod/board:manageboard', $context);
    }
}

function clear_history() {
    global $DB;
    
    return $DB->delete_records_select('board_history', 'timecreated < :timecreated', array('timecreated' => time() - 60)); // 1 minute history
}

function board_get($boardid) {
    global $DB;
    
    require_capability_for_board_view($boardid);
    
    if (!$board = $DB->get_record('board', array('id'=>$boardid))) {
        return [];
    }
    
    $columns = $DB->get_records('board_columns', array('boardid' => $boardid), 'id', 'id, name');
    foreach ($columns AS $columnid => $column) {
        $column->notes = $DB->get_records('board_notes', array('columnid' => $columnid), 'id', 'id, userid, content');
    }
    
    clear_history();
    return $columns;
};

function board_history($boardid, $since) {
    global $DB;
    
    require_capability_for_board_view($boardid);
    
    if (!$board = $DB->get_record('board', array('id'=>$boardid))) {
        return [];
    }
    
    clear_history();
    return $DB->get_records_select('board_history', "boardid=:boardid AND id > :since", array('boardid' => $boardid, 'since' => $since));
};

function board_add_column($boardid, $name) {
    global $DB, $USER;
    
    $name = substr($name, 0, 100);
    
    require_capability_for_board($boardid);
    
    $transaction = $DB->start_delegated_transaction();
    
    $columnid = $DB->insert_record('board_columns', array('boardid' => $boardid, 'name' => $name));
    $historyid = $DB->insert_record('board_history', array('boardid' => $boardid, 'action' => 'add_column', 'columnid' => $columnid, 'userid' => $USER->id, 'content' => $name, 'timecreated' => time()));
    $DB->update_record('board', array('id' => $boardid, 'historyid' => $historyid));
    $transaction->allow_commit();
    
    board_add_column_log($boardid, $name, $columnid);
  
    clear_history();
    return array('id' => $columnid, 'historyid' => $historyid);
}

function board_add_column_log($boardid, $name, $columnid) {
    $event = \mod_board\event\add_column::create(array(
        'objectid' => $columnid,
        'context' => context_module::instance(coursemodule_for_board(get_board($boardid))->id),
        'other' => array('name' => $name)
    ));
    $event->trigger();
}

function board_update_column($id, $name) {
    global $DB, $USER;
    
    $name = substr($name, 0, 100);
    
    require_capability_for_column($id);
    
    $boardid = $DB->get_field('board_columns', 'boardid', array('id' => $id));
    if ($boardid) {
        $transaction = $DB->start_delegated_transaction();
        $update = $DB->update_record('board_columns', array('id' => $id, 'name' => $name));
        $historyid = $DB->insert_record('board_history', array('boardid' => $boardid, 'action' => 'update_column', 'columnid' => $id, 'userid' => $USER->id, 'content' => $name, 'timecreated' => time()));
        $DB->update_record('board', array('id' => $id, 'historyid' => $historyid));
        $transaction->allow_commit();
        
        board_update_column_log($boardid, $name, $id);
    } else {
        $update = false;
        $historyid = 0;
    }
    
    clear_history();
    return array('status' => $update, 'historyid' => $historyid);
}

function board_update_column_log($boardid, $name, $columnid) {
    $event = \mod_board\event\update_column::create(array(
        'objectid' => $columnid,
        'context' => context_module::instance(coursemodule_for_board(get_board($boardid))->id),
        'other' => array('name' => $name)
    ));
    $event->trigger();
}

function board_delete_column($id) {
    global $DB, $USER;
    
    require_capability_for_column($id);
    
    $boardid = $DB->get_field('board_columns', 'boardid', array('id' => $id));
    if ($boardid) {
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('board_notes', array('columnid' => $id));
        $delete = $DB->delete_records('board_columns', array('id' => $id));
        $historyid = $DB->insert_record('board_history', array('boardid' => $boardid, 'action' => 'delete_column', 'columnid' => $id, 'userid' => $USER->id, 'timecreated' => time()));
        $DB->update_record('board', array('id' => $boardid, 'historyid' => $historyid));
        $transaction->allow_commit();
        
        board_delete_column_log($boardid, $id);
    } else {
        $delete = false;
        $historyid = 0;
    }
    
    clear_history();
    return array('status' => $delete, 'historyid' => $historyid);
}

function board_delete_column_log($boardid, $columnid) {
    $event = \mod_board\event\delete_column::create(array(
        'objectid' => $columnid,
        'context' => context_module::instance(coursemodule_for_board(get_board($boardid))->id)
    ));
    $event->trigger();
}

function require_capability_for_note($id) {
    global $DB, $USER;
    
    if (!$note = $DB->get_record('board_notes', array('id'=>$id))) {
        return false;
    }
    
    $context = context_for_column($note->columnid);
    if ($context) {
        require_capability('mod/board:view', $context);
        
        if ($USER->id != $note->userid) {
            require_capability('mod/board:manageboard', $context);
        }
    }
}

function board_add_note($columnid, $content) {
    global $DB, $USER;
    
    $context = context_for_column($columnid);
    if ($context) {
        require_capability('mod/board:view', $context);
    }
    
    $boardid = $DB->get_field('board_columns', 'boardid', array('id' => $columnid));
    
    if ($boardid) {
        $transaction = $DB->start_delegated_transaction();
        $noteid = $DB->insert_record('board_notes', array('columnid' => $columnid, 'content' => $content, 'userid' => $USER->id));
        $historyid = $DB->insert_record('board_history', array('boardid' => $boardid, 'action' => 'add_note', 'columnid' => $columnid, 'noteid' => $noteid, 'userid' => $USER->id, 'content' => $content, 'timecreated' => time()));
        $DB->update_record('board', array('id' => $boardid, 'historyid' => $historyid));
        $transaction->allow_commit();
        
        board_add_note_log($boardid, $content, $columnid, $noteid);
    } else {
        $noteid = 0;
        $historyid = 0;
    }

    clear_history();
    return array('id' => $noteid, 'historyid' => $historyid);
}

function board_add_note_log($boardid, $content, $columnid, $noteid) {
    $event = \mod_board\event\add_note::create(array(
        'objectid' => $noteid,
        'context' => context_module::instance(coursemodule_for_board(get_board($boardid))->id),
        'other' => array('columnid' => $columnid, 'content' => $content)
    ));
    $event->trigger();
}

function board_update_note($id, $content) {
    global $DB, $USER;
    
    require_capability_for_note($id);
    
    $columnid = $DB->get_field('board_notes', 'columnid', array('id' => $id));
    $boardid = $DB->get_field('board_columns', 'boardid', array('id' => $columnid));
    if ($columnid && $boardid) {
        $transaction = $DB->start_delegated_transaction();
        $historyid = $DB->insert_record('board_history', array('boardid' => $boardid, 'action' => 'update_note', 'columnid' => $columnid, 'noteid' => $id, 'userid' => $USER->id, 'content' => $content, 'timecreated' => time()));
        $update = $DB->update_record('board_notes', array('id' => $id, 'content' => $content));
        $DB->update_record('board', array('id' => $boardid, 'historyid' => $historyid));
        $transaction->allow_commit();
        
        board_update_note_log($boardid, $content, $columnid, $id);
    } else {
        $update = false;
        $historyid = 0;
    }
    
    clear_history();
    return array('status' => $update, 'historyid' => $historyid);
}

function board_update_note_log($boardid, $content, $columnid, $noteid) {
    $event = \mod_board\event\update_note::create(array(
        'objectid' => $noteid,
        'context' => context_module::instance(coursemodule_for_board(get_board($boardid))->id),
        'other' => array('columnid' => $columnid, 'content' => $content)
    ));
    $event->trigger();
}

function board_delete_note($id) {
    global $DB, $USER;
    
    require_capability_for_note($id);
    
    $columnid = $DB->get_field('board_notes', 'columnid', array('id' => $id));
    $boardid = $DB->get_field('board_columns', 'boardid', array('id' => $columnid));
    
    if ($columnid && $boardid) {
        $transaction = $DB->start_delegated_transaction();    
        $delete = $DB->delete_records('board_notes', array('id' => $id));
        $historyid = $DB->insert_record('board_history', array('boardid' => $boardid, 'action' => 'delete_note', 'columnid' => $columnid, 'noteid' => $id, 'userid' => $USER->id, 'timecreated' => time()));
        $DB->update_record('board', array('id' => $boardid, 'historyid' => $historyid));
        $transaction->allow_commit();
        
        board_delete_note_log($boardid, $columnid, $id);
    } else {
        $delete = false;
        $historyid = 0;
    }
    clear_history();
    return array('status' => $delete, 'historyid' => $historyid);
}

function board_delete_note_log($boardid, $columnid, $noteid) {
    $event = \mod_board\event\delete_note::create(array(
        'objectid' => $noteid,
        'context' => context_module::instance(coursemodule_for_board(get_board($boardid))->id),
        'other' => array('columnid' => $columnid)
    ));
    $event->trigger();
}
