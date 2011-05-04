<?php
/**
 * Pollmanager Plugin: allows to create and manage polls
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Stephane Chazelas <stephane@artesyncp.com>
 *
 * Start point was Stephane Chazelas <stephane@artesyncp.com>'s
 * userpoll plugin which in turn is
 * heavily inspired (copy-pasted) by
 *             Esther Brunner <wikidesign@gmail.com>'s poll plugin
 */
/**
 *
 * TODO:
 * * Refactor code into the render() function. (Especially for the 
 * _xmlEntities() function.) Done, sort of.
 * * Apply style. (Currently hardcoded for vote results bar.
 * * Sorting of polls (by date).
 * * Multiple choice questions.
 * * Permissions for editing polls. More editing options.
 * * Close polls, possibility to see closed polls.
 * * Permissions for deleting polls. Backups. Deleting closed 
 * polls.
 * * Input hidden fields for poll identification and "write".
 * * Configuration for displaying only titles or the whole poll on 
 * the main page.
 * * Form validation.
 * * Add swedish.
 * * Email notification.
 * * Automatic poll closing.
 * * Possibility to add comments to ones vote.
 * * Menu?
 * * Is multiple files for closed/open polls a good option?
 * * Administration options.
 *
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_pollmanager extends DokuWiki_Syntax_Plugin {

    /**
     * return some info
     */
    function getInfo() {
        return array(
            'author' => 'Adam Viklund',
            'email'  => 'adam.viklund@gmail.com',
            'date'   => '2011-03-29',
            'name'   => 'Pollmanager Plugin',
            'desc'   => 'Create and manage polls.',
            'url'    => '',
        );
    }

    function getType() {
        return 'substition';
    }
    function getPType() {
        return 'block';
    }
    function getSort() {
        return 167;
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('{{pollmanager}}', $mode, 'plugin_pollmanager');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){
        // Same syntax each time.
        return 1;
    }

    /**
     * Create output
     */
    function render($mode, &$renderer, $data) {
        if ($mode != 'xhtml') {
            return false;
        }

        // prevent caching to ensure the poll results are fresh
        $renderer->info['cache'] = FALSE;

        // The data file is a md5 checksum of the given string, 
        // placed in folder /path/to/dokuwiki/data/meta/ 
        // (indicated by the metaFN() function.
        // "$polls" will hold all the array of all the active polls.
        $pollssum = md5('@pollmanager@');
        $pollsfile = metaFN($pollssum, '.pollmanager');
        $polls  = unserialize(@file_get_contents($pollsfile));

        // If the request comes with a poll id, cast to 
        // integer.
        $pollid = NULL;
        if (isset($_POST['pollid'])) {
            $pollid = (int)$_POST['pollid'];
        }

        // Bring up the form for creating polls. If $pollid is set
        // a poll will be edited.
        if (!empty($_REQUEST['new_poll'])) {
            $renderer->doc .= $this->_newPollForm($polls,
                $pollid);
            return true;
        }

        $user = $_SERVER['REMOTE_USER'];

        // Request field "write" set by hidden fields. Used to identify 
        // which requests are expected to write to the data file.
        if (isset($_POST['write'])) {
            if (!empty($_POST['vote']) && !empty($_POST['poll'])) {
                $vote = $_POST['poll'];
                $polls[$pollid]['users'][$user] = $vote[$pollid];
            }
            elseif (!empty($_POST['unset'])) {
                unset($polls[$pollid]['users'][$user]);
            }
            elseif (!empty($_POST['open'])) {
                $closed_polls = md5('@pollmanager_closed@');
                $closed_file = metaFN($closed_polls, '.pollmanager');
                $closed_data  =
                    unserialize(@file_get_contents($closed_file));
                $polls[] = $closed_data[$pollid];
                unset($closed_data[$pollid]);
                $fh = fopen($closed_file, 'w');
                fwrite($fh, serialize($closed_data));
                fclose($fh);
            }
            elseif (!empty($_POST['delete'])) {
                // Back up the poll and delete it from the $polls 
                // array.
                $backup_polls = md5('@pollmanager_deleted@');
                $backup_file = metaFN($backup_polls, '.pollmanager');
                $backup_data  =
                    unserialize(@file_get_contents($backup_file));
                $backup_data[] = $polls[$pollid];
                $fh = fopen($backup_file, 'w');
                fwrite($fh, serialize($backup_data));
                fclose($fh);
                unset($polls[$pollid]);
            }
            elseif (!empty($_POST['close'])) {
                $closed_polls = md5('@pollmanager_closed@');
                $closed_file = metaFN($closed_polls, '.pollmanager');
                $closed_data  =
                    unserialize(@file_get_contents($closed_file));
                $closed_data[] = $polls[$pollid];
                $fh = fopen($closed_file, 'w');
                fwrite($fh, serialize($closed_data));
                fclose($fh);
                unset($polls[$pollid]);
            }
            elseif (!empty($_POST['create_poll'])) {
                // Check if date is valid and extract to a good 
                // format.
                $raw_date = $_POST['date'];
                $datelength = strlen($raw_date);
                $date = strtotime(substr($raw_date,0,4)."-".
                    substr($raw_date,4,2)."-".
                    substr($raw_date,-2));
                if ($datelength == 0) {
                    $date = NULL;
                }
                elseif ($datelength != 8 || $date == false) {
                    $renderer->doc .= $this->_newPollForm($polls,
                        $pollid, 'The date has wrong format.');
                    return true;
                }


                // Current time as creation time.
                $createtime = date('YmdHis');
                //$pfilename = md5('@pollmanager@'.$createtime);

                // Deciding on what ID the poll gets. If $pollid 
                // is set also the createtime was set before.
                $i = 0;
                if (isset($pollid)) {
                    $i = $pollid;
                    $createtime = $polls[$pollid]['created'];
                }
                else {
                    while (!empty($polls[$i])) {
                        $i++;
                    }
                }

                $choices = $_POST['c'];
                if (isset($choices)) {
                    foreach ($choices as $cid=>$choice) {
                        $choice = trim($choice);
                        if ($choice == "") {
                            unset($choices[$cid]);
                        }
                        else {
                            $choice_fill[] =
                                $renderer->_xmlEntities($choice);
                        }
                    }
                }
                $option = $renderer->_xmlEntities($option);
                $polls[$i] = array(
                    'created' => $createtime,
                    'date' => $date,
                    'title' => $renderer->_xmlEntities($_POST['title']),
                    'question' => $renderer->_xmlEntities($_POST['question']),
                    'creator' => $_SERVER['REMOTE_USER'],
                    'choices' => $choice_fill);
            }

            // Write results to data file.
            $fh = fopen($pollsfile, 'w');
            fwrite($fh, serialize($polls));
            fclose($fh);
        }

        global $ID;
        $renderer->doc .= '<a href="'.wl($ID).'&new_poll=1">'.
            $this->getLang('new_poll').'</a><br />';
        $renderer->doc .= '<a href="'.wl($ID).'&closed_polls=1">'.
            $this->getLang('closed_polls').'</a>';

        if (isset($_REQUEST['closed_polls'])) {
            $polls = unserialize(@file_get_contents(
                metaFN(md5('@pollmanager_closed@'), '.pollmanager')));
            $closed = true;
        }
        $renderer->doc .= $this->_showPolls($polls, $closed);
        return true;

    }

    function _showPolls($polls, $closed = NULL) {
        global $ID;
        $text = '';

        if (!is_array($polls)) {
            return $text;
        }

        foreach ($polls as $pollid=>$poll) {
            $results = $this->_getResults($poll);

            $text .= '<h3>'.$poll['title'].'</h3>'.
                '<form method="post" action="'.wl($ID).'">'.
                '<p>'.$poll['question'].'</p>';
            if (isset($poll['date'])) {
                $text .= '<p>'.date("Y m d", $poll['date']).
                    '</p>';
            }
            $choices = $poll['choices'];
            $text .= '<table><tr><td>'.$this->getLang('choices').'</td>'.
                '<td>'.$this->getLang('votes').'</td><td></td><td>'.
                $this->getLang('respondents').'</td></tr>';

            if (isset($choices)) {
                foreach ($choices as $cid=>$choice) {
                    $text .= '<tr><td><label>';
                    if (!$closed) {
                        $text .= '<input type="radio" name="poll['.
                            $pollid.']" value="'.$cid.'" />';
                    }
                    $text .= $choice.'</label></td>';
                    $text .= '<td>'.$results['count'][$cid].'</td>';
                    $text .= '<td>';
                    $text .= '<div style="width:200px; height:20px;'.
                        'border:1px solid black;">';
                    if ($results['votes'] > 0) {
                        $text .= '<div style="background-color:#714400; '.
                            'height:20px; width:'.
                            (100*$results['count'][$cid]/$results['votes']).
                            '%;"></div>';
                    }
                    $text .= '</div>';
                    $text .= '</td>';
                    $text .= '<td>';
                    if (isset($results['choice'][$cid])) {
                        $users = $results['choice'][$cid];
                        foreach ($users as $user) {
                            $text .= $user.'<br>';
                        }
                    }
                    $text .= '</td></tr>';
                }
            }
            $text .= '</table>';
            $text .= '<input type="hidden" name="write" value="" />';
            $text .= '<input type="hidden" name="pollid" value="'.
                $pollid.'"/>';
            if (!$closed) {
                $text .= '<input type="submit" name="vote" value="'.
                    $this->getLang('vote').'" />';
                $text .= '<input type="submit" name="unset['.$pollid.
                    ']" value="'.
                    $this->getLang('unset_vote').'" />';
                $text .= '<input type="submit" name="close" value="'.
                    $this->getLang('close_poll').'" />';
                $text .= '<input type="submit" name="new_poll" value="'.
                    $this->getLang('edit_poll').'" />';
                $text .= '<input type="submit" name="delete" value="'.
                    $this->getLang('delete_poll').'" />';
            }
            else {
                $text .= '<input type="submit" name="open" value="'.
                    $this->getLang('reopen_poll').'" />';
            }
            $text .= '</form>';
        }
        return $text;
    }

    function _newPollForm($polls, $pollid = NULL, $error = NULL) {
        global $ID;
        $title = '';
        $question = '';
        $date = '';
        $hidden = '';
        $choices = NULL;
        $new_choices = 0;
        $new_start = 0;
        if ($_POST['new_poll'] == $this->getLang('add')) {
            $new_choices = 5;
        }
        if ($_POST['new_poll'] == $this->getLang('add') 
            || isset($_POST['create_poll'])) {
                $title = $_POST['title'];
                $question = $_POST['question'];
                $date = $_POST['date'];
                $choices = $_POST['c'];
                if (isset($pollid)) {
                    $hidden = '<input type="hidden" name="pollid" value="'.
                        $pollid.'" />';
                }
            }
        elseif ($_POST['new_poll'] == $this->getLang('edit_poll')) {
            $title = $polls[$pollid]['title'];
            $question = $polls[$pollid]['question'];
            $date = date('Ymd', $polls[$pollid]['date']);
            $choices = $polls[$pollid]['choices'];
            $hidden = '<input type="hidden" name="pollid" value="'.
                $pollid.'" />';
        }
        else {
            $new_choices = 5;
        }
        $text = '';
        if (isset($error)) {
            $text .= '<p class="error">'.$error.'</p>';
        }
        $text .= '<form action="'.wl($ID).
            '" id="pollmanager" method="post"> ';
        $text .= '<label class="block"><span>'.$this->getLang('title');
        $text .= '</span><input type="text" name="title" value="'.
            $title.'" /></label> ';
        $text .= '<label class="block"><span>'.$this->getLang('question');
        $text .= '</span>';
        $text .= '<input type="textarea" name="question" value="'.
            $question.'" /></label> ';
        $text .= '<label class="block"><span>'.$this->getLang('date');
        $text .= '</span>';
        $text .= '<input type="textarea" name="date" value="'.
            $date.'" /></label> ';
        $text .= $hidden;
        if (isset($choices)) {
            foreach ($choices as $choiceid=>$choice) {
                $text .= '<label class="block"><span>'.
                    $this->getLang('choice');
                $text .= ' '.($choiceid+1).
                    '</span><input type="text" name="c['.$choiceid;
                $text .= ']" value="'.$choice.'" /></label>';
                $new_start = $choiceid+1;
            }
        }
        for ($i = $new_start; $i < $new_choices+$new_start ; $i++) {
            $text .= '<label class="block"><span>'.
                $this->getLang('choice');
            $text .= ' '.($i+1).
                '</span><input type="text" name="c['.$i;
            $text .= ']" /></label>';
        }
        $text .= '<input type="hidden" name="write" value="1" />';
        $text .= '<input type="submit" name="new_poll" value="'.
            $this->getLang('add').'" />';
        $text .= '<input type="submit" name="create_poll" value="'.
            $this->getLang('submit').'" /> ';

        $text .= '</form>';
        return $text;
    }

    static function _getResults($poll) {
        $votes = $poll['users'];
        $results['num_votes'] = count($votes);

        $choices = $poll['choices'];
        if (isset($choices)) {
            foreach ($choices as $id=>$choice) {
                $results['count'][$id] = 0;
            }
        }
        if (isset($votes)) {
            foreach ($votes as $user=>$vote) {
                $results['choice'][$vote][] = $user;
                $results['count'][$vote] += 1;
                $results['votes'] += 1;
            }
        }
        return $results;
    }

}

?> 
