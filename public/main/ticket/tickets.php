<?php

/* For licensing terms, see /license.txt */

use Chamilo\CoreBundle\Enums\ActionIcon;
use Chamilo\CoreBundle\Enums\ObjectIcon;
use Chamilo\CoreBundle\Enums\ToolIcon;
use ChamiloSession as Session;

/**
 * This script is the Tickets plugin main entry point.
 */
require_once __DIR__.'/../inc/global.inc.php';

api_block_anonymous_users();

$tool_name = get_lang('Ticket');
$isAdmin = api_is_platform_admin();
$user_id = api_get_user_id();

$webLibPath = api_get_path(WEB_LIBRARY_PATH);
$htmlHeadXtra[] = '<script>
function load_history_ticket(div_course, ticket_id) {
    $.ajax({
        contentType: "application/x-www-form-urlencoded",
        beforeSend: function(object) {
        $("div#"+div_course).html("<img src=\''.$webLibPath.'javascript/indicator.gif\' />"); },
        type: "POST",
        url: "ticket_assign_log.php",
        data: "ticket_id="+ticket_id,
        success: function(data) {
            $("div#div_"+ticket_id).html(data);
            $("div#div_"+ticket_id).attr("class","blackboard_show");
            $("div#div_"+ticket_id).attr("style","");
        }
    });
}
function clear_course_list(div_course) {
    $("div#"+div_course).html("&nbsp;");
    $("div#"+div_course).hide("");
}

$(function() {
    $("#advanced_search_form").css("display","none");
});

function display_advanced_search_form () {
    if ($("#advanced_search_form").css("display") == "none") {
        $("#advanced_search_form").css("display","block");
        $("#img_plus_and_minus").html(\'&nbsp;'.Display::getMdiIcon('arrow-down-bold').' '.get_lang('Advanced search').'\');
    } else {
        $("#advanced_search_form").css("display","none");
        $("#img_plus_and_minus").html(\'&nbsp;'.Display::getMdiIcon('arrow-right-bold').' '.get_lang('Advanced search').'\');
    }
}
</script>';

$this_section = 'tickets';
Session::erase('this_section');

$action = isset($_GET['action']) ? $_GET['action'] : '';
$projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;

$table = new SortableTable(
    'Tickets',
    ['TicketManager', 'getTotalTicketsCurrentUser'],
    ['TicketManager', 'getTicketsByCurrentUser'],
    2,
    20,
    'DESC'
);

$table->set_additional_parameters(['project_id' => $projectId]);

if (0 == $table->per_page) {
    $table->per_page = 20;
}

switch ($action) {
    case 'alert':
        if (!$isAdmin && isset($_GET['ticket_id'])) {
            TicketManager::send_alert($_GET['ticket_id'], $user_id);
        }

        break;
    case 'export':
        $data = [
            [
                '#',
                get_lang('Date'),
                get_lang('Last update'),
                get_lang('Category'),
                get_lang('User'),
                get_lang('Course Program</a>. If your course has no code, whatever the reason, invent one. For instance <i>INNOVATION</i> if the course is about Innovation Management'),
                get_lang('Assigned to'),
                get_lang('Status'),
                get_lang('Description'),
            ],
        ];
        $datos = $table->get_clean_html();
        foreach ($datos as $ticket) {
            $ticket[0] = substr(strip_tags($ticket[0]), 0, 12);
            $ticket_rem = [
                utf8_decode(strip_tags($ticket[0])),
                utf8_decode(api_html_entity_decode($ticket[1])),
                utf8_decode(strip_tags($ticket[2])),
                utf8_decode(strip_tags($ticket[3])),
                utf8_decode(strip_tags($ticket[4])),
                utf8_decode(strip_tags($ticket[5])),
                utf8_decode(strip_tags($ticket[6])),
                utf8_decode(strip_tags($ticket[7])),
            ];
            $data[] = $ticket_rem;
        }
        Export::arrayToXls($data, get_lang('Tickets'));
        exit;

        break;
    case 'close_tickets':
        TicketManager::close_old_tickets();

        break;
    default:
        break;
}

if (empty($projectId)) {
    $projects = TicketManager::getProjectsSimple();
    if (!empty($projects) && isset($projects[0])) {
        $project = $projects[0];
        header('Location: '.api_get_self().'?project_id='.$project['id']);
        exit;
    }
}

$currentUrl = api_get_self().'?project_id='.$projectId;
$isAllow = TicketManager::userIsAllowInProject($projectId);
$actionRight = '';

Display::display_header(get_lang('My tickets'));

if (!empty($projectId)) {
    $getParameters = [];
    if ($isAdmin) {
        $getParameters = [
            'keyword',
            'keyword_status',
            'keyword_category',
            'keyword_assigned_to',
            'keyword_start_date',
            'keyword_unread',
            'Tickets_per_page',
            'Tickets_column',
        ];
    }
    $get_parameter = '';
    foreach ($getParameters as $getParameter) {
        if (isset($_GET[$getParameter])) {
            $get_parameter .= "&$getParameter=".Security::remove_XSS($_GET[$getParameter]);
        }
    }

    $getParameters = [
        'Tickets_per_page',
        'Tickets_column',
    ];
    $get_parameter2 = '';
    foreach ($getParameters as $getParameter) {
        if (isset($_GET[$getParameter])) {
            $get_parameter2 .= "&$getParameter=".Security::remove_XSS($_GET[$getParameter]);
        }
    }

    if (isset($_GET['submit_advanced'])) {
        $get_parameter .= '&submit_advanced=';
    }
    if (isset($_GET['submit_simple'])) {
        $get_parameter .= '&submit_simple=';
    }

    // Select categories
    $selectTypes = [];
    $types = TicketManager::get_all_tickets_categories($projectId);
    foreach ($types as $type) {
        $selectTypes[$type['category_id']] = $type['title'];
    }

    $admins = UserManager::getUserListLike(
        ['status' => '1'],
        ['username'],
        true
    );
    $selectAdmins = [
        0 => get_lang('Unassigned'),
    ];
    foreach ($admins as $admin) {
        $selectAdmins[$admin['user_id']] = $admin['complete_name_with_username'];
    }
    $status = TicketManager::get_all_tickets_status();
    $selectStatus = [];
    foreach ($status as $stat) {
        $selectStatus[$stat['id']] = $stat['title'];
    }

    $selectPriority = TicketManager::getPriorityList();
    $selectUnread = [
        '' => get_lang('All'),
        'yes' => get_lang('Unread'),
        'no' => get_lang('Read'),
    ];

    // Create a search-box
    $form = new FormValidator(
        'search_simple',
        'get',
        $currentUrl,
        null,
        null,
        'inline'
    );
    $form->addText('keyword', get_lang('Keyword'), false);
    $form->addButtonSearch(get_lang('Search'), 'submit_simple');
    $form->addHidden('project_id', $projectId);

    $advancedSearch = Display::url(
        '<span id="img_plus_and_minus">&nbsp;'.
        Display::getMdiIcon('arrow-right-bold').' '.get_lang('Advanced search'),
        'javascript://',
        [
            'class' => 'btn btn--plain advanced-parameters',
            'onclick' => 'display_advanced_search_form();',
        ]
    );

    // Add link
    if ('true' === api_get_setting('ticket_allow_student_add') || api_is_platform_admin()) {
        $actionRight = Display::url(
            Display::getMdiIcon(ActionIcon::ADD, 'ch-tool-icon', null, ICON_SIZE_MEDIUM, get_lang('Add')),
            api_get_path(WEB_CODE_PATH).'ticket/new_ticket.php?project_id='.$projectId,
            ['title' => get_lang('Add')]
        );
    }

    if (api_is_platform_admin()) {
        $actionRight .= Display::url(
            Display::getMdiIcon(ActionIcon::EXPORT_SPREADSHEET, 'ch-tool-icon', null, ICON_SIZE_MEDIUM, get_lang('Export')),
            api_get_self().'?action=export'.$get_parameter.$get_parameter2.'&project_id='.$projectId,
            ['title' => get_lang('Export')]
        );

        $actionRight .= Display::url(
            Display::getMdiIcon(ToolIcon::SETTINGS, 'ch-tool-icon', null, ICON_SIZE_MEDIUM, get_lang('Settings')),
            api_get_path(WEB_CODE_PATH).'ticket/settings.php',
            ['title' => get_lang('Settings')]
        );
    }

    echo Display::toolbarAction(
        'toolbar-tickets',
        [
            $actionRight,
            $advancedSearch,
            $form->returnForm(),
        ]
    );

    $ticketLabel = get_lang('All tickets');
    $url = api_get_path(WEB_CODE_PATH).'ticket/tickets.php?project_id='.$projectId;

    if (!isset($_GET['keyword_assigned_to'])) {
        $ticketLabel = get_lang('My tickets');
        $url = api_get_path(WEB_CODE_PATH).'ticket/tickets.php?project_id='.$projectId.'&keyword_assigned_to='.api_get_user_id();
    }

    $options = '';
    $iconProject = Display::getMdiIcon(ObjectIcon::PROJECT, 'ch-tool-icon', null, ICON_SIZE_MEDIUM, get_lang('Projects'));
    if ($isAdmin) {
        $options .= Display::url(
            $iconProject,
            api_get_path(WEB_CODE_PATH).'ticket/projects.php'
        );
    }
    $iconTicket = Display::getMdiIcon(
        ObjectIcon::TICKET,
        'ch-tool-icon',
        null,
        ICON_SIZE_MEDIUM,
        $ticketLabel
    );
    $options .= Display::url(
        $iconTicket,
        $url
    );

    if ($isAllow) {
        echo Display::toolbarAction('toolbar-options', [$options]);
    }

    $advancedSearchForm = new FormValidator(
        'advanced_search',
        'get',
        $currentUrl,
        null,
        ['style' => 'display:"none"', 'id' => 'advanced_search_form']
    );

    $advancedSearchForm->addHidden('project_id', $projectId);
    $advancedSearchForm->addHeader(get_lang('Advanced search'));
    $advancedSearchForm->addSelect(
        'keyword_category',
        get_lang('Category'),
        $selectTypes,
        ['placeholder' => get_lang('Select')]
    );
    $advancedSearchForm->addDateTimePicker('keyword_start_date_start', get_lang('Created'));
    $advancedSearchForm->addDateTimePicker('keyword_start_date_end', get_lang('Until'));
    $advancedSearchForm->addSelect(
        'keyword_assigned_to',
        get_lang('Assigned to'),
        $selectAdmins,
        ['placeholder' => get_lang('All')]
    );
    $advancedSearchForm->addSelect(
        'keyword_status',
        get_lang('Status'),
        $selectStatus,
        ['placeholder' => get_lang('Select')]
    );
    $advancedSearchForm->addSelect(
        'keyword_priority',
        get_lang('Priority'),
        $selectPriority,
        ['placeholder' => get_lang('All')]
    );
    $advancedSearchForm->addText('keyword_course', get_lang('Course'), false);
    $advancedSearchForm->addButtonSearch(get_lang('Advanced search'), 'submit_advanced');
    $advancedSearchForm->display();
} else {
    if ('true' === api_get_setting('ticket_allow_student_add')) {
        echo '<div class="actions">';
        echo '<a href="'.api_get_path(WEB_CODE_PATH).'ticket/new_ticket.php?project_id='.$projectId.'">'.
                Display::getMdiIcon(ActionIcon::ADD, 'ch-tool-icon', null, ICON_SIZE_MEDIUM, get_lang('Add')).
             '</a>';
        echo '</div>';
    }
}

if ($isAdmin) {
    $table->set_header(0, '#', true);
    $table->set_header(1, get_lang('Status'), true);
    $table->set_header(2, get_lang('Date'), true);
    $table->set_header(3, get_lang('Last update'), true);
    $table->set_header(4, get_lang('Category'), true);
    $table->set_header(5, get_lang('Created by'), true);
    $table->set_header(6, get_lang('Assigned to'), true);
    $table->set_header(7, get_lang('Message'), true);
} else {
    if (false == $isAllow) {
        echo Display::page_subheader(get_lang('My tickets'));
        echo Display::return_message(
            get_lang(
                'Welcome to YOUR tickets section. Here, you\'ll be able to track the state of all the tickets you created in the main tickets section.'
            )
        );
    }
    $table->set_header(0, '#', true);
    $table->set_header(1, get_lang('Status'), false);
    $table->set_header(2, get_lang('Date'), true);
    $table->set_header(3, get_lang('Last update'), true);
    $table->set_header(4, get_lang('Category'));
}

$table->display();
Display::display_footer();
