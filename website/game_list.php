<?php

require_once('mysql_login.php');
require_once('memcache.php');
require_once('pagination.php');
//require_once('api_functions.php');

//require_once('session.php');
// session is needed to highlight the current user
// but is not included here so that json requests go faster
// you must include it in your php if you wish to render html tables

// used for looking at latest results only with view more link
//  should be smaller than page size
$top_results_size = 10;
// used for looking at pages with pagination links
$page_size = 50;

// this function doesn't really belong here, but I can't think of a good place
// it works like filter_input with an optional filter and default value
function get_type_or_else($key, $type=NULL, $default=NULL) {
    if (isset($_GET[$key])) {
        $value = $_GET[$key];
        if ($type == NULL) {
            return $value;
        } else {
            $filter_value = filter_var($value, $type, FILTER_NULL_ON_FAILURE);
            if ($filter_value !== NULL) {
                return $filter_value;
            }
        }
    }
    return $default;
}

function cache_key($page=0, $user_id=NULL, $submission_id=NULL, $map_id=NULL, $format='json') {
    if ($page == -1) {
        $page = "page_count";
    }
    if ($user_id !== NULL) {
        $key = "game_list:user:" . strval($user_id);
    } elseif ($submission_id !== NULL) {
        $key = "game_list:submission:" . strval($submission_id);
    } elseif ($map_id !== NULL) {
        $key = "game_list:map:" . strval($map_id);
    } else {
        $key = "game_list:all::";
    }
    return $key. ":" . strval($page) . ":" . $format;
}

// page 0 is all results, page 1 is the top N results
function produce_cache_results($page=0, $user_id=NULL, $submission_id=NULL, $map_id=NULL) {
    global $memcache;
    global $page_size;

    $page_count_query = "select_game_list_page_count";
    $list_query = "select_game_list";
    if ($user_id !== NULL) {
        $list_select_field = "user_id";
        $list_id = $user_id;
        $list_type = "user";
        $list_id_field = "username";
    } elseif ($submission_id !== NULL) {
        $list_select_field = "submission_id";
        $list_id = $submission_id;
        $list_type = "submission";
        $list_id_field = "submission_id";
    } elseif ($map_id !== NULL) {
        $page_count_query = "select_game_list_page_count";
        $list_query = "select_game_list";
        $list_select_field = "map_id";
        $list_id = $map_id;
        $list_type = "map";
        $list_id_field = "map_id";
    } else {
        // make the where clause always return true
        $page_count_query = "select_map_game_list_page_count";
        $list_query = "select_map_game_list";
        $list_select_field = "1";
        $list_id = 1;
        $list_type = NULL;
    }
    $list_results = contest_query($page_count_query, $list_select_field, $list_id);
    if ($list_results) {
        while ($list_row = mysql_fetch_array($list_results, MYSQL_NUM)) {
            $row_count = $list_row[0];
            $page_count = ceil($row_count / $page_size);
        }
    }
    $memcache->set(cache_key(-1, $user_id, $submission_id, $map_id), $page_count);
    if ($page == 0) {
        $offset = 0;
        $limit = $row_count;
    } else {
        $offset = ($page - 1) * $page_size;
        $limit = $page_size;
    }
    $json = array("fields" => array(),
                  "values" => array());
    if ($page > 0) {
        $json["page"] = $page;
        $json["page_count"] = $page_count;
    }
    $list_results = contest_query($list_query, $list_select_field, $list_id, $limit, $offset);
    $list_id_name = NULL;
    if ($list_results) {
        $field_count = mysql_num_fields($list_results);
        $row_count = mysql_num_rows($list_results);
        $field_names = array();
        for ($i = 0; $i < $field_count; $i++) {
            $field_names[] = mysql_field_name($list_results, $i);
        }
        $json["fields"] = $field_names;
        if ($user_id !== NULL or $submission_id !== NULL) {
            $json["fields"][] = 'user_mu';
            $json["fields"][] = 'user_rank';
            $json["fields"][] = 'user_version';
        }
        $row_num = 0;
        $game_row_num = 0;
        $page_num = 0;
        $last_game_id = -1;
        $cur_row = NULL;
        while ($list_row = mysql_fetch_array($list_results, MYSQL_NUM)) {
            // get list name, run once
            if ($list_type and !$list_id_name) {
                $list_row_by_name = array_combine($field_names, $list_row);
                $list_id_name = $list_row_by_name[$list_id_field];
            }
            // get additional opponent info
            if ($list_row[0] == $last_game_id) {
                $cur_row[2][] = $list_row[2];
                $cur_row[3][] = $list_row[3];
                $cur_row[4][] = $list_row[4];
                $cur_row[5][] = $list_row[5];
                $cur_row[6][] = $list_row[6];
                $cur_row[7][] = $list_row[7];
                $cur_row[8][] = $list_row[8];
                $cur_row[9][] = $list_row[9];
                if ($list_row[2] == $user_id or $list_row[3] == $submission_id) {
                    $cur_row[] = $list_row[6] - $list_row[5]*3;
                    $cur_row[] = $list_row[8];
                    $cur_row[] = $list_row[9];
                }
            } else {
            // get new game info
                // dump results of row
                if ($cur_row !== NULL) {
                    $json["values"][] = $cur_row;
                    $game_row_num++;
                }
                // setup new row
                $row_num++;
                $cur_row = $list_row;
                $cur_row[2] = array($cur_row[2]);
                $cur_row[3] = array($cur_row[3]);
                $cur_row[4] = array($cur_row[4]);
                $cur_row[5] = array($cur_row[5]);
                $cur_row[6] = array($cur_row[6]);
                $cur_row[7] = array($cur_row[7]);
                $cur_row[8] = array($cur_row[8]);
                $cur_row[9] = array($cur_row[9]);
                if ($list_row[2] == $user_id or $list_row[3] == $submission_id) {
                    $cur_row[] = $list_row[6] - $list_row[5]*3;
                    $cur_row[] = $list_row[8];
                    $cur_row[] = $list_row[9];
                }
            }
            $last_game_id = $list_row[0];
        }
        // dump results
        if ($list_type) {
            $json["type"] = $list_type;
            $json["type_id"] = $list_id;
            $json["type_name"] = $list_id_name;
        } else {
            $json["type"] = "all";
        }
        $json_result = json_encode($json);
        $memcache->set(cache_key($page, $user_id, $submission_id, $map_id),
                       $json_result);
        return $json_result;
    }
    return NULL;
}

function nice_interval($interval) {
    if ($interval->y > 0) {
        return $interval->format('%y yrs %m months');
    } elseif ($interval->m > 0) {
        return $interval->format('%m months %d days');
    } elseif ($interval->d > 0) {
        return $interval->format('%d days %h hrs');
    } elseif ($interval->h > 0) {
        return $interval->format('%h hrs %i min');
    } else {
        return $interval->format('%i min %s sec');
    }
}

function place($num) {
    switch ($num % 10) {
        case 1:
            return strval($num)."st";
            break;
        case 2:
            return strval($num)."nd";
            break;
        case 3:
            return strval($num)."rd";
            break;
        default:
            return strval($num)."th";
    }
}

function no_wrap($data) {
    return str_replace(" ", "&nbsp;", strval($data));
}

function create_game_list_table($json, $top=FALSE, $targetpage=NULL) {
    global $top_results_size;

    if ($targetpage === NULL) {
	$targetpage = $_SERVER['PHP_SELF'];
    }
    if ($json == NULL) {
        return '<h4>There are no games at this time.  Please check back later.</h4>';
    }
    if ($json['type'] == 'user') {
        $user_id = $json['type_id'];
    } else {
        $user_id = NULL;
    }
    $version = 0;
    $table = '<table class="ranking">';
    if (array_key_exists('type', $json)) {
        // language by name, others by id
        if ($json['type'] == 'language') {
            $page_string = '?'.$json['type'].'='.$json['type_name'].'&page=';
        } else {
            $page_string = '?'.$json['type'].'='.$json['type_id'].'&page=';
        }
    } else {
        $page_string = '?page=';
    }
    if (!$top) {
        $table .= '<caption>'.getPaginationString($json['page'], $json['page_count'], 10, $page_string)."</caption>";
    }
    // produce header
    $table .= '<thead><tr><th>Time</th>';
    if ($user_id) {
        $table .= '<th>Skill</th>';
    }
    $table .= '<th>Opponents</th>';
    if ($user_id) {
        $table .= '<th>Outcome</th>';
    }
    $table .= '<th>Map</th><th>Viewer</th></tr></thead>';
    if (count($json["values"]) > 0) {
        $table .= '<tbody>';
        $oddity = 'even';
        $fields = $json["fields"];
        $row_num = 0;
        $now = new DateTime();
        foreach ($json["values"] as $values) {
            $row_num++;
            $row = array_combine($fields, $values);
            
            if ($user_id and $version !== $row["user_version"]) {
            	$version = $row["user_version"];
            	$table .= "<tr colspan=\"6\"><th>Version $version</th></tr>";
            }

            $oddity = $oddity == 'odd' ? 'even' : 'odd';  // quite odd?
            $user_class = current_username() == $row["username"] ? ' user' : '';
            $table .= "<tr class=\"$oddity$user_class\">";

            $time = new DateTime($row["timestamp"]);
            $time = "<span title=\"".no_wrap(nice_interval($now->diff($time))." ago")."\">".no_wrap($time->format('j M G:i'))."</span>";
            $table .= "<td>$time</td>";

            if ($user_id) {
                $skill = number_format(max(0.0, $row["user_mu"]), 2);
                $table .= "<td class=\"number\">$skill</td>";
            }

            // TODO: consider linking the submission id instead
            $opponents = "";
            for ($i = 0; $i < $row['players']; $i++) {
                $opponents .= "<span>(".($row['game_rank'][$i]+1).")<a href=\"profile.php?user="
                              .$row['user_id'][$i]."\">".
                              $row['username'][$i]."</a></span> ";
            }
            $table .= "<td class=\"list\">$opponents</td>";

            if ($user_id) {
                $outcome = no_wrap(place($row['user_rank']+1)." of ".$row['players']);
                $table .= "<td>$outcome</td>";
            }
            $map_name = explode('.', $row['map_name'], 1);
            $pos1 = strrpos($row['map_name'],'/') + 1;
            $pos2 = strrpos($row['map_name'],'.');
            $map_name = substr($row['map_name'], $pos1, $pos2-$pos1);
            $map = "<a href=\"map/".$row['map_name']."\">$map_name</a>";
            $table .= "<td class=\"list\"><span>$map</span></td>";

            $game = "<a href=\"visualizer.php?game=" . $row['game_id'] . "\">Game&nbsp;&raquo;</a>";
            $table .= "<td>$game</td>";

            $table .= "</tr>";
            if ($top and $row_num == $top_results_size) {
                break;
            }
        }
        $table .= '</tbody>';
    }
    if ($top) {
        $table .= '<caption align="bottom"><a href="'.$targetpage.$page_string.'1">View More &raquo;</a></caption>';
    }
    $table .= '</table>';

    return $table;
}

function get_game_list_json($page=0, $user_id=NULL, $submission_id=NULL, $map_id=NULL) {
    global $memcache;

    $cache_key = cache_key($page, $user_id, $submission_id, $map_id);
    if ($memcache) {
        $results = $memcache->get($cache_key);
    }
    $results = NULL; // use to force data refresh when debugging
    if (!$results) {
        $results = produce_cache_results($page, $user_id, $submission_id, $map_id);
    }
    return $results;
}

function get_game_list_table($page=0, $user_id=NULL, $submission_id=NULL, $map_id=NULL, $top=FALSE, $targetpage=NULL) {
    global $memcache;
    $format = $top ? 'top' : 'table';
    $page = $top ? 1 : $page;
    $cache_key = cache_key($page, $user_id, $submission_id, $map_id, $format);
    if ($memcache) {
        $results = $memcache->get($cache_key);
    }
    $results = NULL; // use to force data refresh when debugging
    if (!$results) {
        $json = get_game_list_json($page, $user_id, $submission_id, $map_id);
        $results = create_game_list_table(json_decode($json, true), $top, $targetpage);
        if ($memcache) {
            $memcache->set($cache_key, $results);
        }
    }
    return $results;
}

?>
