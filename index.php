<?php

/**
 * Advanced Spam Cleaner
 *
 * Helps an admin to clean up spam in Moodle
 *
 * @copytight Ankit Agarwal
 * @license GPL3 or later
 */


require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/tablelib.php');
require_once('advanced_form.php');
require_once('lib.php');

// List of known spammy keywords, please add more here
$autokeywords = array(
                    "<img",
                    "fuck",
                    "casino",
                    "porn",
                    "xxx",
                    "cialis",
                    "viagra",
                    "poker",
                    "warcraft"
                );


$del = optional_param('del', '', PARAM_RAW);
$delall = optional_param('delall', '', PARAM_RAW);
$ignore = optional_param('ignore', '', PARAM_RAW);
$id = optional_param('id', '', PARAM_INT);

require_login();
admin_externalpage_setup('tooladvancedspamcleaner');

// Delete one user // sessions are not supported atm
if (!empty($del) && confirm_sesskey() && ($id != $USER->id)) {
    if ($user = $DB->get_record("user", array('id' => $id))) {
        if (delete_user($user)) {
            //unset($SESSION->users_result[$id]);
            echo json_encode(true);
        } else {
            echo json_encode(false);
        }
    } else {
        echo json_encode(false);
    }
    exit;
}

// Delete lots of users
if (!empty($delall) && confirm_sesskey()) {
    if (!empty($SESSION->users_result)) {
        foreach ($SESSION->users_result as $userid => $user) {
            if ($userid != $USER->id) {
                if (delete_user($user)) {
                    unset($SESSION->users_result[$userid]);
                }
            }
        }
    }
    echo json_encode(true);
    exit;
}

if (!empty($ignore)) {
    unset($SESSION->users_result[$id]);
    echo json_encode(true);
    exit;
}



$PAGE->requires->js_init_call('M.tool_spamcleaner.init', array(me()), true);
$strings = Array('spaminvalidresult','spamdeleteallconfirm','spamcannotdelete','spamdeleteconfirm');
$PAGE->requires->strings_for_js($strings, 'tool_spamcleaner');

echo $OUTPUT->header();

// Print headers and things.
echo $OUTPUT->box(get_string('spamcleanerintro', 'tool_advancedspamcleaner'));

$spamcleaner = new advanced_spam_cleaner();
$pluginlist = $spamcleaner->plugin_list(context_system::instance());

$links = array();
$links[] = html_writer::link("https://tracker.moodle.org/browse/CONTRIB/component/12336", get_string('reportissue', 'tool_advancedspamcleaner'), array('target' => '_blank'));
$links[] = html_writer::link("https://moodle.org/plugins/view.php?plugin=tool_advancedspamcleaner", get_string('pluginpage', 'tool_advancedspamcleaner'), array('target' => '_blank'));
$links = html_writer::alist($links);
echo $OUTPUT->box($links);

$mform = new tool_advanced_spam_cleaner(null, array ('pluginlist' => $pluginlist));

if ( $formdata = $mform->get_data()) {
    $mform->display();
    echo html_writer::start_div('mdl-align', array('id' => 'result'));
    $keywords = explode(',', $formdata->keyword);
    if ($formdata->method == 'usekeywords' && empty($formdata->keyword)) {
        print_error(get_string('missingkeywords', 'tool_advancedspamcleaner'));
    }

    // Set limits.
    if (!empty($formdata->uselimits)) {
        if (is_number($formdata->apilimit)) {
            $apilimit = $formdata->apilimit;
        } else {
            $apilimit = 0;
        }

        if (is_number($formdata->hitlimit)) {
            $hitlimit = $formdata->hitlimit;
        } else {
            $hitlimit = 0;
        }
    } else {
        $apilimit = 0;
        $hitlimit = 0;
    }
    $apicount = 0;
    $hitcount = 0;
    $limitflag = false;

    // Date limits.
    if (!empty($formdata->usedatestartlimit)) {
        if (is_number($formdata->startdate)) {
            $starttime = $formdata->startdate;
        } else {
            $starttime = 0;
        }

        if (is_number($formdata->enddate)) {
            $endtime = $formdata->enddate;
        } else {
            $endtime = time();
        }
    } else {
        $starttime = 0;
        $endtime = time();
    }

    // Find spam using keywords.
    if ($formdata->method == 'usekeywords' || $formdata->method == 'spamauto') {
        if (empty($keywords) || $formdata->method == 'spamauto') {
            $keywords = $autokeywords;
        }
        $spamcleaner->search_spammers($formdata, $keywords, $starttime, $endtime, false );
    // Use the specified sub-plugin.
    } else {
        // Store stats for sub-plugins methods.
        $stats = array('time' => time(), 'users' => 0, 'comments' => 0, 'msgs' => 0, 'forums' => 0, 'blogs' => 0);
        $plugin = $formdata->method;
        if (in_array($plugin, $pluginlist)) {
            print_error("Invalid sub plugin");
        }
        $pluginclassname = "$plugin" . "_advanced_spam_cleaner";
        $plugin = new $pluginclassname($plugin);
        $params = array('userid' => $USER->id, 'start' => $starttime, 'end' => $endtime);
        $spamusers = array();

        if (!empty($formdata->searchusers)) {
            $sql  = "SELECT * FROM {user} AS u WHERE deleted = 0 AND id <> :userid AND description != '' AND u.timemodified > :start AND u.timemodified < :end ";  // Exclude oneself
            $users = $DB->get_recordset_sql($sql, $params);
            foreach ($users as $user) {
                // Limit checks
                if(($apilimit != 0 && $apilimit <= $apicount) || ($hitlimit !=0 && $hitlimit <= $hitcount)) {
                    $limitflag = true;
                    break;
                }
                $apicount++;

                // Data should be consistent for the sub-plugins
                $data = new stdClass();
                $data->email = $user->email;
                $data->ip = $user->lastip;
                $data->text = $user->description;
                $data->type = 'userdesc';
                $is_spam = $plugin->detect_spam($data);
                if ($is_spam) {
                    $spamusers[$user->id]['user'] = $user;
                    if(empty($spamusers[$user->id]['spamcount'])) {
                        $spamusers[$user->id]['spamcount'] = 1;
                    } else {
                        $spamusers[$user->id]['spamcount']++;
                    }
                    $spamusers[$user->id]['spamtext'][$spamusers[$user->id]['spamcount']] = array ('userdesc' , $data->text, $user->id);
                    $hitcount++;
                }
                $stats['users']++;
            }
        }
        if (!empty($formdata->searchcomments)) {
            $sql  = "SELECT u.*, c.id as cid, c.content FROM {user} AS u, {comments} AS c WHERE u.deleted = 0 AND u.id=c.userid AND u.id <> :userid AND c.timecreated > :start AND c.timecreated < :end";
            $users = $DB->get_recordset_sql($sql, $params);
            foreach ($users as $user) {
                // Limit checks
                if(($apilimit != 0 && $apilimit <= $apicount) || ($hitlimit !=0 && $hitlimit <= $hitcount)) {
                    $limitflag = true;
                    break;
                }
                $apicount++;

                // Data should be consistent for the sub-plugins
                $data = new stdClass();
                $data->email = $user->email;
                $data->ip = $user->lastip;
                $data->text = $user->content;
                $data->type = 'comment';
                $is_spam = $plugin->detect_spam($data);
                if ($is_spam) {
                    $spamusers[$user->id]['user'] = $user;
                    if(empty($spamusers[$user->id]['spamcount'])) {
                        $spamusers[$user->id]['spamcount'] = 1;
                    } else {
                        $spamusers[$user->id]['spamcount']++;
                    }
                    $hitcount++;
                    $spamusers[$user->id]['spamtext'][$spamusers[$user->id]['spamcount']] = array ('comment' , $data->text, $user->cid);
                }
                $stats['comments']++;
            }
        }
        if (!empty($formdata->searchmsgs)) {
            $sql  = "SELECT u.*, m.id as mid, m.fullmessage FROM {user} AS u, {message} AS m WHERE u.deleted = 0 AND u.id=m.useridfrom AND u.id <> :userid AND m.timecreated > :start AND m.timecreated < :end";
            $users = $DB->get_recordset_sql($sql, $params);
            foreach ($users as $user) {
                // Limit checks
                if (($apilimit != 0 && $apilimit <= $apicount) || ($hitlimit !=0 && $hitlimit <= $hitcount)) {
                    $limitflag = true;
                    break;
                }
                $apicount++;

                // Data should be consistent for the sub-plugins
                $data = new stdClass();
                $data->email = $user->email;
                $data->ip = $user->lastip;
                $data->text = $user->fullmessage;
                $data->type = 'message';
                $is_spam = $plugin->detect_spam($data);
                if ($is_spam) {
                    $spamusers[$user->id]['user'] = $user;
                    if(empty($spamusers[$user->id]['spamcount'])) {
                        $spamusers[$user->id]['spamcount'] = 1;
                    } else {
                        $spamusers[$user->id]['spamcount']++;
                    }
                    $hitcount++;
                    $spamusers[$user->id]['spamtext'][$spamusers[$user->id]['spamcount']] = array ('message' , $data->text, $user->mid);
                }
                $stats['msgs']++;
            }
        }
        if (!empty($formdata->searchforums)) {
            $sql = "SELECT u.*, fp.id as fid, fp.message FROM {user} AS u, {forum_posts} AS fp WHERE u.deleted = 0 AND u.id=fp.userid AND u.id <> :userid AND fp.modified > :start AND fp.modified < :end";
            $users = $DB->get_recordset_sql($sql, $params);
            foreach ($users as $user) {
                // Limit checks
                if(($apilimit != 0 && $apilimit <= $apicount) || ($hitlimit !=0 && $hitlimit <= $hitcount)) {
                    $limitflag = true;
                    break;
                }
                $apicount++;

                // Data should be consistent for the sub-plugins
                $data = new stdClass();
                $data->email = $user->email;
                $data->ip = $user->lastip;
                $data->text = $user->message;
                $data->type = 'forummessage';
                $is_spam = $plugin->detect_spam($data);
                if ($is_spam) {
                    $spamusers[$user->id]['user'] = $user;
                    if(empty($spamusers[$user->id]['spamcount'])) {
                        $spamusers[$user->id]['spamcount'] = 1;
                    } else {
                        $spamusers[$user->id]['spamcount']++;
                    }
                    $hitcount++;
                    $spamusers[$user->id]['spamtext'][$spamusers[$user->id]['spamcount']] = array ('forummessage' , $data->text, $user->fid);
                }
                $stats['forums']++;
            }
        }
        if (!empty($formdata->searchblogs)) {
            $sql = "SELECT u.*, p.id as pid, p.summary FROM {user} AS u, {post} AS p WHERE u.deleted = 0 AND u.id=p.userid AND u.id <> :userid AND p.lastmodified > :start AND p.lastmodified < :end";
            $users = $DB->get_recordset_sql($sql, $params);
            foreach ($users as $user) {
                // Limit checks
                if(($apilimit != 0 && $apilimit <= $apicount) || ($hitlimit !=0 && $hitlimit <= $hitcount)) {
                    $limitflag = true;
                    break;
                }
                $apicount++;

                // Data should be consistent for the sub-plugins
                $data = new stdClass();
                $data->email = $user->email;
                $data->ip = $user->lastip;
                $data->text = $user->summary;
                $data->type = 'blogpost';
                $is_spam = $plugin->detect_spam($data);
                if ($is_spam) {
                    $spamusers[$user->id]['user'] = $user;
                    if (empty($spamusers[$user->id]['spamcount'])) {
                        $spamusers[$user->id]['spamcount'] = 1;
                    } else {
                        $spamusers[$user->id]['spamcount']++;
                    }
                    $hitcount++;
                    $spamusers[$user->id]['spamtext'][$spamusers[$user->id]['spamcount']] = array ('blogpost' , $data->text, $user->pid);
                }
                $stats['blogs']++;
            }
        }
        // Calculate time taken.
        $stats['time'] = time() - $stats['time'];
        echo $OUTPUT->box(get_string('methodused', 'tool_advancedspamcleaner', $plugin->pluginname));
        echo $OUTPUT->box(get_string('showstats', 'tool_advancedspamcleaner', $stats));
        $spamcleaner->print_table($spamusers, '', true, $limitflag);
    }
    echo html_writer::end_div();
} else {
    $mform->display();
}

echo $OUTPUT->footer();
