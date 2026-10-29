<?php
/**
 * THE single source of truth for every sheet's columns.
 *
 * Column tuple: [label, field, type, options]
 *   type: id | text | long | url | date | int | dec | money | select | computed
 *   options: ['list' => 'List Name']  for select
 *            ['calc' => '<calc key>'] for computed
 *
 * Nothing outside this file decides what a grid, an export or the schema
 * contains. Adding a column means adding it here and nowhere else.
 */

/**
 * Bumped whenever the set of sheets changes, so every cache built from it
 * knows to rebuild.
 *
 * Without this, building a sheet and then using it in the same request gives
 * the sheets as they were before — the caches are warm and have no way to
 * know. Page loads hid it because each one starts cold; the builder did not.
 */
function fabo_schema_generation(bool $bump = false): int
{
    static $gen = 0;
    if ($bump) {
        $gen++;
    }
    return $gen;
}

function fabo_sheets(): array
{
    // Cached per organisation, because the sheets somebody built are part of
    // the answer and one client's are not another's.
    static $cache = [];
    $owner = function_exists('fabo_view_owner') ? fabo_view_owner() : 0;
    $ck    = $owner . ':' . fabo_schema_generation();
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    $sheets = null;

    $sheets = [

    // ------------------------------------------------------------------
    'master' => [
        'label'    => 'Master Activity Log',
        'title'    => 'MASTER ACTIVITY LOG',
        'subtitle' => 'Central Tracker for All Marketing Activities (B2B & B2C)',
        'table'    => 'master_log',
        'prefix'   => 'ACT-',
        'pad'      => 4,
        'id_field' => 'activity_id',
        'brand'    => 'brand',
        'bu'       => 'business_unit',
        'columns'  => [
            ['Activity ID',          'activity_id',      'id'],
            ['Brand',                'brand',            'select',   ['list' => 'Brand']],
            ['Business Unit',        'business_unit',    'select',   ['list' => 'Business Unit']],
            ['Function',             'function_name',    'select',   ['list' => 'Function']],
            ['Sub-Function',         'sub_function',     'select',   ['list' => 'All Sub-Functions']],
            ['Activity / Task Name', 'activity_name',    'text'],
            ['Description',          'description',      'long'],
            ['Owner',                'owner',            'select',   ['list' => 'Owner / Team']],
            ['Reporting Cadence',    'reporting_cadence','select',   ['list' => 'Reporting Cadence']],
            ['Linked Detail ID',     'linked_detail_id', 'text'],
            ['Start Date',           'start_date',       'date'],
            ['Due Date',             'due_date',         'date'],
            ['Completion Date',      'completion_date',  'date'],
            ['Status',               'status',           'select',   ['list' => 'Status']],
            ['Priority',             'priority',         'select',   ['list' => 'Priority']],
            ['% Complete',           'pct_complete',     'int'],
            ['Budget Allocated',     'budget_allocated', 'computed', ['calc' => 'link_money']],
            ['Budget Spent',         'budget_spent',     'computed', ['calc' => 'link_money']],
            ['Leads Generated',      'leads_generated',  'computed', ['calc' => 'link_int']],
            ['Revenue Attributed',   'revenue_attributed','computed',['calc' => 'link_money']],
            ['ROI %',                'roi_pct',          'computed', ['calc' => 'link_pct']],
            ['Location / Geography', 'location',         'select',   ['list' => 'Geography']],
            ['Store Tag',            'store_tag',        'select',   ['list' => 'Store Tag']],
            ['Platform / Channel',   'platform',         'select',   ['list' => 'Platform / Channel']],
            ['Deliverable Link',     'deliverable_link', 'url'],
            ['Days to Deadline',     'days_to_deadline', 'computed', ['calc' => 'days']],
            ['RAG Status',           'rag_status',       'computed', ['calc' => 'rag']],
            ['Month',                'month_label',      'computed', ['calc' => 'month']],
            ['Quarter',              'quarter_label',    'computed', ['calc' => 'quarter']],
            ['Week Number',          'week_number',      'computed', ['calc' => 'week']],
            ['Year',                 'year_label',       'computed', ['calc' => 'year']],
            ['Remarks',              'remarks',          'long'],
            ['Checklist',            'checklist',        'computed', ['calc' => 'checklist']],
        ],
        'date_driver' => 'due_date',
    ],

    // ------------------------------------------------------------------
    'store' => [
        'label'    => 'Store Tracker',
        'title'    => 'STORE TRACKER',
        'subtitle' => 'Brand → State / City → Store (New & Existing)',
        'table'    => 'store_tracker',
        'prefix'   => 'STR-',
        'pad'      => 3,
        'id_field' => 'store_id',
        'brand'    => 'brand',
        'bu'       => null,
        'columns'  => [
            ['Store ID',              'store_id',        'id'],
            ['Brand',                 'brand',           'select',   ['list' => 'Brand']],
            ['State',                 'state',           'select',   ['list' => 'State']],
            ['City',                  'city',            'select',   ['list' => 'Geography']],
            ['Store Name',            'store_name',      'text'],
            ['Store Type',            'store_type',      'select',   ['list' => 'Store Type']],
            ['Status',                'status',          'select',   ['list' => 'Store Status']],
            ['Opening Date',          'opening_date',    'date'],
            ['Match Keyword',         'match_keyword',   'text'],
            ['BTL Activities',        'btl_activities',  'computed', ['calc' => 'store_roll']],
            ['BTL Budget Spent',      'btl_spend',       'computed', ['calc' => 'store_roll']],
            ['BTL Leads/Walk-ins',    'btl_leads',       'computed', ['calc' => 'store_roll']],
            ['Design Assets',         'design_assets',   'computed', ['calc' => 'store_roll']],
            ['DM Budget Spent',       'dm_spend',        'computed', ['calc' => 'store_roll']],
            ['DM Leads',              'dm_leads',        'computed', ['calc' => 'store_roll']],
            ['Total Marketing Spend', 'total_spend',     'computed', ['calc' => 'store_roll']],
            ['Total Leads/Walk-ins',  'total_leads',     'computed', ['calc' => 'store_roll']],
            ['Checklist',            'checklist',        'computed', ['calc' => 'checklist']],
        ],
        'date_driver' => null,
    ],

    // ------------------------------------------------------------------
    'calendar' => [
        'label'    => 'Social Media Calendar Management',
        'title'    => 'SOCIAL MEDIA CALENDAR MANAGEMENT',
        'subtitle' => 'Indian Festivals & Key Dates',
        'table'    => 'social_calendar',
        'prefix'   => null,
        'pad'      => 0,
        'id_field' => null,
        'brand'    => 'brand',
        'bu'       => null,
        'columns'  => [
            ['Date',                                    'occasion_date',  'date'],
            ['Day',                                     'day_label',      'computed', ['calc' => 'day']],
            ['Occasion',                                'occasion',       'text'],
            ['Brand',                                   'brand',          'select', ['list' => 'Brand']],
            ['Category',                                'category',       'select',   ['list' => 'Occasion Category']],
            ['Why It Matters',                          'why_it_matters', 'long'],
            ['Content Angle 1',                         'brand1_angle',   'long'],
            ['Content Angle 2',                         'brand2_angle',   'long'],
            ['Suggested Platform',                      'platform',       'select',   ['list' => 'Suggested Platform']],
            ['Priority',                                'priority',       'select',   ['list' => 'Priority']],
            ['Content Status',                          'content_status', 'select',   ['list' => 'Content Status']],
            ['Linked Content ID',                       'linked_content_id', 'text'],
            ['Month',                                   'month_label',    'computed', ['calc' => 'month']],
            ['Week Number',                             'week_number',    'computed', ['calc' => 'week']],
            ['Quarter',                                 'quarter_label',  'computed', ['calc' => 'quarter']],
            ['Upcoming Flag',                           'upcoming_flag',  'computed', ['calc' => 'upcoming']],
            ['Checklist',            'checklist',        'computed', ['calc' => 'checklist']],
        ],
        'date_driver' => 'occasion_date',
    ],

    // ------------------------------------------------------------------
    'content' => [
        'label'    => 'Content Management',
        'title'    => 'CONTENT MANAGEMENT',
        'subtitle' => 'Blogs, Website Copy, LinkedIn & Instagram Posts',
        'table'    => 'content_tracker',
        'prefix'   => 'CNT-',
        'pad'      => 3,
        'id_field' => 'content_id',
        'brand'    => 'brand',
        'bu'       => 'business_unit',
        'columns'  => [
            ['Content ID',           'content_id',       'id'],
            ['Brand',                'brand',            'select',   ['list' => 'Brand']],
            ['Business Unit',        'business_unit',    'select',   ['list' => 'Business Unit']],
            ['Content Type',         'content_type',     'select',   ['list' => 'Content Type']],
            ['Title / Topic',        'title_topic',      'text'],
            ['Platform',             'platform',         'select',   ['list' => 'Platform / Channel']],
            ['Owner / Writer',       'owner',            'select',   ['list' => 'Owner / Team']],
            ['Brief / Notes',        'brief_notes',      'long'],
            ['Draft Due Date',       'draft_due_date',   'date'],
            ['Review Date',          'review_date',      'date'],
            ['Planned Publish Date', 'planned_publish_date', 'date'],
            ['Actual Publish Date',  'actual_publish_date',  'date'],
            ['Status',               'status',           'select',   ['list' => 'Status']],
            ['URL / Link',           'url_link',         'url'],
            ['Reach / Views',        'reach_views',      'int'],
            ['Likes/Reactions',      'likes',            'int'],
            ['Comments',             'comments_count',   'int'],
            ['Shares',               'shares',           'int'],
            ['Engagement Rate',      'engagement_rate',  'computed', ['calc' => 'content_er']],
            ['Month',                'month_label',      'computed', ['calc' => 'month']],
            ['Quarter',              'quarter_label',    'computed', ['calc' => 'quarter']],
            ['Remarks',              'remarks',          'long'],
            ['Content Files',        'asset_count',      'computed', ['calc' => 'assets']],
            ['Send for Approval',    'approval_to',      'select',   ['list' => '@clients']],
            ['Requested From',       'approval_requested','computed',['calc' => 'approval']],
            ['Approval',             'approval_state',   'computed', ['calc' => 'approval']],
            ['Approval Remarks',     'approval_remarks', 'computed', ['calc' => 'approval']],
            ['Checklist',            'checklist',        'computed', ['calc' => 'checklist']],
        ],
        'approval'    => true,
        'assets_label'=> 'content',
        'date_driver' => 'planned_publish_date',
    ],

    // ------------------------------------------------------------------
    'website' => [
        'label'    => 'Website & SEO Management',
        'title'    => 'WEBSITE & SEO MANAGEMENT',
        'subtitle' => 'SEO, AIO, GEO, Content Updates, Banners',
        'note'     => 'AIO = AI Optimization (visibility in AI assistants) · GEO = Generative Engine Optimization (visibility in ChatGPT/Perplexity/AI Overviews)',
        'table'    => 'website_tracker',
        'prefix'   => 'WEB-',
        'pad'      => 3,
        'id_field' => 'task_id',
        'brand'    => 'brand',
        'bu'       => 'business_unit',
        'columns'  => [
            ['Task ID',                     'task_id',          'id'],
            ['Brand',                       'brand',            'select', ['list' => 'Brand']],
            ['Business Unit',               'business_unit',    'select', ['list' => 'Business Unit']],
            ['Category',                    'category',         'select', ['list' => 'Category']],
            ['Task / Page',                 'task_page',        'text'],
            ['Owner',                       'owner',            'select', ['list' => 'Owner / Team']],
            ['Target Keyword / Focus Area', 'target_keyword',   'text'],
            ['Start Date',                  'start_date',       'date'],
            ['Due Date',                    'due_date',         'date'],
            ['Completion Date',             'completion_date',  'date'],
            ['Status',                      'status',           'select', ['list' => 'Status']],
            ['Priority',                    'priority',         'select', ['list' => 'Priority']],
            ['Current Position',            'current_position', 'int'],
            ['Target Position',             'target_position',  'int'],
            ['Monthly Organic Traffic',     'organic_traffic',  'int'],
            ['Tool Used',                   'tool_used',        'text'],
            ['Month',                       'month_label',      'computed', ['calc' => 'month']],
            ['Quarter',                     'quarter_label',    'computed', ['calc' => 'quarter']],
            ['Remarks',                     'remarks',          'long'],
            ['Checklist',            'checklist',        'computed', ['calc' => 'checklist']],
        ],
        'date_driver' => 'due_date',
    ],

    // ------------------------------------------------------------------
    'design' => [
        'label'    => 'Design Management',
        'title'    => 'DESIGN MANAGEMENT',
        'subtitle' => 'Brochures, Leaflets, Ad Designs, Social Post Designs, Banners, Store Designs, POPs',
        'table'    => 'design_tracker',
        'prefix'   => 'DSN-',
        'pad'      => 3,
        'id_field' => 'design_id',
        'brand'    => 'brand',
        'bu'       => 'business_unit',
        'columns'  => [
            ['Design ID',           'design_id',        'id'],
            ['Brand',               'brand',            'select', ['list' => 'Brand']],
            ['Business Unit',       'business_unit',    'select', ['list' => 'Business Unit']],
            ['Design Type',         'design_type',      'select', ['list' => 'Design Type']],
            ['Project / Title',     'project_title',    'text'],
            ['Requested By',        'requested_by',     'select', ['list' => 'Owner / Team']],
            ['Designer / Owner',    'designer',         'select', ['list' => 'Owner / Team']],
            ['Brief Link',          'brief_link',       'url'],
            ['Request Date',        'request_date',     'date'],
            ['Due Date',            'due_date',         'date'],
            ['Completion Date',     'completion_date',  'date'],
            ['Status',              'status',           'select', ['list' => 'Status']],
            ['Priority',            'priority',         'select', ['list' => 'Priority']],
            ['Revisions',           'revisions',        'int'],
            ['Final File Link',     'final_file_link',  'url'],
            ['Print or Digital',    'print_or_digital', 'select', ['list' => 'Print or Digital']],
            ['Quantity (if print)', 'quantity',         'int'],
            ['Store / Location',    'store_location',   'text'],
            ['Month',               'month_label',      'computed', ['calc' => 'month']],
            ['Quarter',             'quarter_label',    'computed', ['calc' => 'quarter']],
            ['Remarks',             'remarks',          'long'],
            ['Design Files',        'asset_count',      'computed', ['calc' => 'assets']],
            ['Send for Approval',   'approval_to',      'select',   ['list' => '@clients']],
            ['Requested From',      'approval_requested','computed',['calc' => 'approval']],
            ['Approval',            'approval_state',   'computed', ['calc' => 'approval']],
            ['Approval Remarks',    'approval_remarks', 'computed', ['calc' => 'approval']],
            ['Checklist',            'checklist',        'computed', ['calc' => 'checklist']],
        ],
        'approval'    => true,
        'assets_label'=> 'design',
        'date_driver' => 'due_date',
    ],

    // ------------------------------------------------------------------
    'campaigns' => [
        'label'    => 'DM Campaigns',
        'title'    => 'DIGITAL MARKETING — CAMPAIGNS',
        'subtitle' => 'Budget, Spend, Leads, ROI by Campaign',
        'table'    => 'dm_campaigns',
        'prefix'   => 'CMP-',
        'pad'      => 3,
        'id_field' => 'campaign_id',
        'brand'    => 'brand',
        'bu'       => 'business_unit',
        'columns'  => [
            ['Campaign ID',          'campaign_id',      'id'],
            ['Brand',                'brand',            'select', ['list' => 'Brand']],
            ['Business Unit',        'business_unit',    'select', ['list' => 'Business Unit']],
            ['Campaign Name',        'campaign_name',    'text'],
            ['Platform / Channel',   'platform',         'select', ['list' => 'Platform / Channel']],
            ['Objective',            'objective',        'select', ['list' => 'Objective']],
            ['Start Date',           'start_date',       'date'],
            ['End Date',             'end_date',         'date'],
            ['Status',               'status',           'select', ['list' => 'Status']],
            ['Location / Geography', 'location',         'text'],
            ['Target Audience',      'target_audience',  'text'],
            ['Store Tag',            'store_tag',        'select', ['list' => 'Store Tag']],
            ['Budget Allocated',     'budget_allocated', 'money'],
            ['Budget Spent',         'budget_spent',     'money'],
            ['Budget Remaining',     'budget_remaining', 'computed', ['calc' => 'budget_remaining']],
            ['Leads Generated',      'leads_generated',  'int'],
            ['Cost per Lead',        'cost_per_lead',    'computed', ['calc' => 'cpl']],
            ['Conversions',          'conversions',      'int'],
            ['Revenue Attributed',   'revenue_attributed','money'],
            ['ROI %',                'roi_pct',          'computed', ['calc' => 'roi_spent']],
            ['Impressions',          'impressions',      'int'],
            ['Clicks',               'clicks',           'int'],
            ['CTR %',                'ctr_pct',          'computed', ['calc' => 'ctr']],
            ['Owner',                'owner',            'select', ['list' => 'Owner / Team']],
            ['Month',                'month_label',      'computed', ['calc' => 'month']],
            ['Quarter',              'quarter_label',    'computed', ['calc' => 'quarter']],
            ['Remarks',              'remarks',          'long'],
            ['Performance Score',    'performance_score','computed', ['calc' => 'score']],
            ['Checklist',            'checklist',        'computed', ['calc' => 'checklist']],
        ],
        'date_driver' => 'start_date',
    ],

    // ------------------------------------------------------------------
    'influencers' => [
        'label'    => 'Influencer Management',
        'title'    => 'INFLUENCER MANAGEMENT',
        'subtitle' => '',
        'table'    => 'dm_influencers',
        'prefix'   => 'INF-',
        'pad'      => 3,
        'id_field' => 'influencer_id',
        'brand'    => 'brand',
        'bu'       => 'business_unit',
        'columns'  => [
            ['Influencer ID',               'influencer_id',    'id'],
            ['Brand',                       'brand',            'select', ['list' => 'Brand']],
            ['Business Unit',               'business_unit',    'select', ['list' => 'Business Unit']],
            ['Influencer Name',             'influencer_name',  'text'],
            ['Platform',                    'platform',         'select', ['list' => 'Platform / Channel']],
            ['Handle / URL',                'handle_url',       'url'],
            ['Follower Count',              'follower_count',   'int'],
            ['Engagement Rate',             'engagement_rate',  'dec'],
            ['Category / Niche',            'niche',            'text'],
            ['Deliverable',                 'deliverable',      'text'],
            ['Linked Campaign ID',          'linked_campaign_id','text'],
            ['Location / Geography',        'location',         'text'],
            ['Agreed Budget',               'agreed_budget',    'money'],
            ['Amount Paid',                 'amount_paid',      'money'],
            ['Content Due Date',            'content_due_date', 'date'],
            ['Content Live Date',           'content_live_date','date'],
            ['Status',                      'status',           'select', ['list' => 'Status']],
            ['Post Link',                   'post_link',        'url'],
            ['Reach',                       'reach',            'int'],
            ['Engagement (Likes+Comments)', 'engagement',       'int'],
            ['Leads / Clicks Generated',    'leads_clicks',     'int'],
            ['Revenue Attributed',          'revenue_attributed','money'],
            ['ROI %',                       'roi_pct',          'computed', ['calc' => 'roi_paid']],
            ['Owner',                       'owner',            'select', ['list' => 'Owner / Team']],
            ['Month',                       'month_label',      'computed', ['calc' => 'month']],
            ['Quarter',                     'quarter_label',    'computed', ['calc' => 'quarter']],
            ['Remarks',                     'remarks',          'long'],
            ['Checklist',            'checklist',        'computed', ['calc' => 'checklist']],
        ],
        'date_driver' => 'content_due_date',
    ],

    // ------------------------------------------------------------------
    'btl' => [
        'label'    => 'BTL Activities',
        'title'    => 'BTL ACTIVITIES',
        'subtitle' => 'Sales & Ops Trainings, On-Ground Promotions',
        'table'    => 'btl_activities',
        'prefix'   => 'BTL-',
        'pad'      => 3,
        'id_field' => 'btl_id',
        'brand'    => 'brand',
        'bu'       => 'business_unit',
        'columns'  => [
            ['BTL ID',                     'btl_id',           'id'],
            ['Brand',                      'brand',            'select', ['list' => 'Brand']],
            ['Business Unit',              'business_unit',    'select', ['list' => 'Business Unit']],
            ['Activity Type',              'activity_type',    'select', ['list' => 'Sub-Function (BTL)']],
            ['Title / Description',        'title_description','text'],
            ['Store / Location',           'store_location',   'text'],
            ['City / Geography',           'city',             'select', ['list' => 'Geography']],
            ['Trainer / Coordinator',      'coordinator',      'select', ['list' => 'Owner / Team']],
            ['Date',                       'activity_date',    'date'],
            ['Target Audience',            'target_audience',  'text'],
            ['Attendees / Footfall',       'attendees',        'int'],
            ['Budget Allocated',           'budget_allocated', 'money'],
            ['Budget Spent',               'budget_spent',     'money'],
            ['Status',                     'status',           'select', ['list' => 'Status']],
            ['Leads / Walk-ins Generated', 'leads_walkins',    'int'],
            ['Feedback Score (1-5)',       'feedback_score',   'dec'],
            ['Materials Used',             'materials_used',   'text'],
            ['Month',                      'month_label',      'computed', ['calc' => 'month']],
            ['Quarter',                    'quarter_label',    'computed', ['calc' => 'quarter']],
            ['Remarks',                    'remarks',          'long'],
            ['Checklist',            'checklist',        'computed', ['calc' => 'checklist']],
        ],
        'date_driver' => 'activity_date',
    ],


    // ------------------------------------------------------------------
    'events' => [
        'label'    => 'Events',
        'title'    => 'EVENTS',
        'subtitle' => 'Launches, Activations, Exhibitions & Franchise Meets — with the full Programme Calendar',
        'table'    => 'events',
        'prefix'   => 'EVT-',
        'pad'      => 3,
        'id_field' => 'event_id',
        'brand'    => 'brand',
        'bu'       => 'business_unit',
        'columns'  => [
            ['Event ID',             'event_id',          'id'],
            ['Brand',                'brand',             'select', ['list' => 'Brand']],
            ['Business Unit',        'business_unit',     'select', ['list' => 'Business Unit']],
            /* Which hub or spoke the programme belongs to. Its own column
               rather than a reading of the business unit, because the two
               answer different questions -- and because a sheet shared with
               a spoke stamps this on every row it sends back. */
            ['Hub / Spoke',          'hub_spoke',         'select', ['list' => 'Hub / Spoke']],
            ['Event Name',           'event_name',        'text'],
            ['Event Type',           'event_type',        'select', ['list' => 'Event Type']],
            ['Start Date',           'start_date',        'date'],
            ['End Date',             'end_date',          'date'],
            ['Location / Store',     'location',          'text'],
            /* Called District because that is the unit these programmes are
               planned by. The stored name stays `city`, so no data moves. */
            ['District',             'city',              'select', ['list' => 'Geography']],
            ['Owner',                'owner',             'select', ['list' => 'Owner / Team']],
            ['Status',               'status',            'select', ['list' => 'Status']],
            /* Whether the event's results are in: Not due, Due, Overdue, In
               progress or Submitted. Kept by the portal from the dates, what
               has been typed and the results link -- see lib/evresults.php. */
            ['Results',              'results_state',     'computed', ['calc' => 'results']],
            ['Priority',             'priority',          'select', ['list' => 'Priority']],
            /* Kept by the portal, not typed: it is the sum of the budget
               lines the committee has approved on this row, so it moves on its
               own whenever a decision does. See fabo_budget_sync_allocated(). */
            ['Budget Allocated',     'budget_allocated',  'money',
                ['kept' => 'Filled from the budget the committee approved on this row.']],
            ['Budget Spent',         'budget_spent',      'money'],
            /* Not a figure somebody types: the cell opens the event's budget
               form, where each head the admin allows gets one box. What shows
               here is the total asked for, red once any head is over its
               limit. See lib/budget.php. */
            ['Budget Required',      'budget_required',   'computed', ['calc' => 'budget']],
            /* Papers on the event itself, as opposed to the ones filed against
               a single budget head: permissions, approvals, the venue contract.
               Same drawer Content and Design have had all along. */
            ['Attachments',          'asset_count',       'computed', ['calc' => 'assets']],

            // ---- Metrics ----------------------------------------------
            // Each one is a pair: what was promised, and what came of it.
            // The metric's name is the banner over the two (see 'group'), so
            // the headings themselves need only say which half they are.
            //
            // 'unlock' names a date the row must have passed before the cell
            // can be written. An achieved figure typed while the event is
            // still running is a guess, and a guess in a results column is
            // worse than an empty one — see fabo_cell_locked().
            ['Expected', 'expected_ideas',         'int',   ['group' => 'No. of Ideas Generated']],
            ['Achieved', 'achieved_ideas',         'int',   ['group' => 'No. of Ideas Generated',
                                                             'unlock' => 'end_date']],
            ['Expected', 'expected_ip',            'int',   ['group' => "No. of IP's Granted"]],
            ['Achieved', 'achieved_ip',            'int',   ['group' => "No. of IP's Granted",
                                                             'unlock' => 'end_date']],
            ['Expected', 'expected_funding',       'money', ['group' => 'Amount of Funding Done']],
            ['Achieved', 'achieved_funding',       'money', ['group' => 'Amount of Funding Done',
                                                             'unlock' => 'end_date']],
            ['Expected', 'expected_dpiit',         'int',   ['group' => 'Startups Registered under DPIIT']],
            ['Achieved', 'achieved_dpiit',         'int',   ['group' => 'Startups Registered under DPIIT',
                                                             'unlock' => 'end_date']],
            ['Expected', 'expected_cin',           'int',   ['group' => 'Companies Registered under CIN']],
            ['Achieved', 'achieved_cin',           'int',   ['group' => 'Companies Registered under CIN',
                                                             'unlock' => 'end_date']],
            ['Expected', 'expected_participants',  'int',   ['group' => 'Total No. of Participants']],
            ['Achieved', 'achieved_participants',  'int',   ['group' => 'Total No. of Participants',
                                                             'unlock' => 'end_date']],
            ['Expected', 'expected_hours',         'dec',   ['group' => 'No. of Hours']],
            ['Achieved', 'achieved_hours',         'dec',   ['group' => 'No. of Hours',
                                                             'unlock' => 'end_date']],
            ['Expected', 'expected_attendees',     'int',   ['group' => 'Attendees']],
            ['Achieved', 'actual_attendees',       'int',   ['group' => 'Attendees',
                                                             'unlock' => 'end_date']],
            ['Leads Generated',      'leads_generated',   'int'],
            ['Linked Campaign ID',   'linked_campaign_id','text'],
            ['Creative Assets Due',  'creative_due_date', 'date'],

            // ---- The programme record behind an event -------------------
            // An event on the calendar is a programme: who it is for, who is
            // running it with us, what it is meant to achieve, and who to
            // call. Read and edited from the calendar's detail panel as much
            // as from this grid.
            ['Programme Details',    'programme_details', 'long'],
            ['Sector',               'sector',            'select', ['list' => 'Sector']],
            ['Target Audience',      'target_audience',   'select', ['list' => 'Target Audience']],
            ['Partners',             'partners',          'select', ['list' => 'Partner']],
            ['Partner Details',      'partner_details',   'long'],
            ['Partners Count',       'partners_count',    'int'],
            ['Leads Expected',       'leads_expected',    'int'],
            ['Duration (hrs)',       'duration_hours',    'dec'],
            ['Duration Planned (hrs)','duration_hours_expected', 'dec'],
            ['Funding Source',       'funding_source',    'select', ['list' => 'Funding Source']],
            ['Local Resources',      'local_resources',   'long'],
            ['Case Studies',         'case_studies',      'long'],
            ['SPOC Name',            'spoc_name',         'text'],
            ['SPOC Email',           'spoc_email',        'text'],
            ['SPOC Contact',         'spoc_contact',      'text'],

            /* ---- Design & publicity support --------------------------
               Yes asks for creatives to promote the event. Only what the
               Design & Requisition sheet needs and this row does not already
               say is asked here: the event's name, dates, venue, partners,
               hub / spoke, brand and SPOC are taken from the row itself.
               Nothing reaches the design team until the event is approved;
               then the requisition is raised and everyone is told -- see
               lib/eventdesign.php. The design_* questions show only once
               this is Yes (the add and fill boxes hide them till then). */
            ['Request Design & Publicity Support', 'design_support', 'select', ['list' => 'Design Support']],
            ['Type of Creative',     'design_req_type',     'select', ['list' => 'Requisition Type', 'group' => 'Design Requisition']],
            ['Creatives Required For', 'design_required_for', 'select', ['list' => 'Requisition Purpose', 'group' => 'Design Requisition']],
            ['No. of Creatives',     'design_qty',          'int',    ['group' => 'Design Requisition']],
            ['Theme',                'design_theme',        'text',   ['group' => 'Design Requisition']],
            ['Online / Offline',     'design_mode',         'select', ['list' => 'Delivery Mode', 'group' => 'Design Requisition']],
            ['Content to go on the Creative', 'design_content', 'long', ['group' => 'Design Requisition']],
            ['Registration Link / QR', 'design_reg_link',   'url',    ['group' => 'Design Requisition']],
            ['Last Date to Register', 'design_register_by', 'date',   ['group' => 'Design Requisition']],
            ['Creatives Needed By',  'design_required_by',  'date',   ['group' => 'Design Requisition']],
            ['Requisition',          'design_req_ref',      'text',   ['group' => 'Design Requisition',
                'kept' => 'Raised automatically when the event is approved: the requisition ID once it is in the design queue.']],

            /* Kept by the committee's decision, not typed: Pending while it
               reviews, then Approved or Rejected with its remarks. The
               dashboards count events by this, so it has to agree with the
               committee. See fabo_cm_signoff() in lib/committees.php. */
            ['Sign-off',             'approval_status',   'select', ['list' => 'Sign-off',
                'kept' => 'Set by the committee\'s decision. Choose a Committee to have the event reviewed.']],
            ['Sign-off Note',        'approval_note',     'text',
                ['kept' => 'The committee\'s remarks, written when it decides.']],

            /* The decision itself: an event is approved or rejected by its
               committee and nobody else (no "Send for Approval" to a person
               here -- see lib/committees.php), with who and why. */
            ['Approval',             'approval_state',    'computed', ['calc' => 'approval']],
            ['Approval Remarks',     'approval_remarks',  'computed', ['calc' => 'approval']],

            /* A committee deciding together: picked from the organisation's
               own committees (Sheet Management -> Committees), and where its
               members stand. See lib/committees.php. */
            ['Committee',            'committee',         'select',   ['list' => '@committees']],
            ['Committee Approval',   'committee_state',   'computed', ['calc' => 'committee']],

            ['Days to Event',        'days_to_event',     'computed', ['calc' => 'days_plain']],
            ['Month',                'month_label',       'computed', ['calc' => 'month']],
            ['Quarter',              'quarter_label',     'computed', ['calc' => 'quarter']],
            ['Remarks',              'remarks',           'long'],
            // Photographs of the event, as many as there are. Stored as
            // attachments on the row, the same as a department's uploads,
            // so both land in the one gallery.
            ['Images',               'row_images',        'images'],
            ['Checklist',            'checklist',        'computed', ['calc' => 'checklist']],
        ],
        'approval'    => true,
        /* Turns on the per-row Files drawer — the paperclip beside each row,
           for documents rather than pictures. Content and Design have had it
           since the start; an event has at least as much paper behind it. */
        'assets_label'=> 'event',
        'date_driver' => 'start_date',
    ],

    // ------------------------------------------------------------------
    // ------------------------------------------------------------------
    'requisitions' => [
        'label'    => 'Design & Requisition Management',
        'title'    => 'DESIGN & REQUISITION MANAGEMENT',
        'subtitle' => 'What has been asked for, who asked, and when the queue says it lands',
        'table'    => 'requisitions',
        'prefix'   => 'REQ-',
        'pad'      => 4,
        'id_field' => 'req_id',
        'brand'    => 'brand',
        'bu'       => null,
        /* The colour and the countdown follow the date the queue promised,
           not the date somebody asked for: a promise that has been extended
           is the one the row should be judged against. */
        'date_driver' => 'promised_due',
        'columns'  => [
            ['Requisition ID',       'req_id',            'id'],
            ['Date Raised',          'raised_on',         'date'],
            ['Request Raised By',    'raised_by',         'text'],
            ['Their Contact',        'raised_by_phone',   'text'],
            ['Their Email',          'raised_by_email',   'text'],
            // The hub and every spoke, from the one list Sheet Management
            // keeps -- the same list the live sheets and events use.
            ['Hub / Spoke',          'spoke',             'select', ['list' => 'Hub / Spoke']],
            ['SPOC for Event',       'spoc_name',         'text'],
            ['SPOC Contact',         'spoc_contact',      'text'],
            ['Brand',                'brand',             'select', ['list' => 'Brand']],

            // What is being asked for. The type is what sets the turnaround,
            // so it is the one field the queue cannot do without.
            ['Type of Requirement',  'req_type',          'select', ['list' => 'Requisition Type']],
            ['Creatives Required For', 'required_for',    'select', ['list' => 'Requisition Purpose']],
            ['No. of Creatives',     'qty',               'int'],
            ['Name of Event / Programme', 'event_name',   'text'],
            ['Theme',                'theme',             'text'],
            ['Event Starts',         'event_start',       'date'],
            ['Event Ends',           'event_end',         'date'],
            ['Partners (if any)',    'partners',          'text'],
            ['Venue (if any)',       'venue',             'text'],
            ['Online / Offline',     'mode',              'select', ['list' => 'Delivery Mode']],
            ['Content to go on the Creative', 'content',  'long'],
            ['Registration Link / QR', 'registration_link', 'url'],
            ['Last Date to Register', 'register_by',      'date'],
            ['Required By',          'required_by',       'date'],
            ['Reference Files',      'row_images',        'images'],

            // Where it sits in the queue, and what the queue promised.
            ['Queue Position',       'queue_position',    'int'],
            ['Promised Start',       'promised_start',    'date'],
            ['Promised Delivery',    'promised_due',      'date'],
            ['Turnaround (hrs)',     'turnaround_hours',  'int'],
            ['Times Extended',       'extended_count',    'int'],
            ['Reason for Extension', 'extension_reason',  'text'],
            ['Status',               'status',            'select', ['list' => 'Requisition Status']],
            ['Assigned To',          'owner',             'select', ['list' => 'Owner / Team']],
            ['Delivered On',         'delivered_on',      'date'],
            ['Deliverable Link',     'deliverable_link',  'url'],
            ['Remarks',              'remarks',           'long'],
            // Whether the person who asked was actually told, in words. A
            // confirmation that never went is a promise nobody heard.
            ['Confirmation',         'confirm_state',     'text'],

            // Computed: the colour. Red once the promised date has passed and
            // the work is still open, which is exactly what an extension does.
            ['Standing',             'rag_status',        'computed', ['calc' => 'rag']],
            ['Days to Delivery',     'days_to_deadline',  'computed', ['calc' => 'days']],
            ['Checklist',            'checklist',         'computed', ['calc' => 'checklist']],
        ],
    ],

    // ------------------------------------------------------------------
    /* Posts written from a website.
     *
     * Every row here started as one address somebody typed. The crawl, the
     * brand read, the content plan and the copy all happened behind it, and
     * what lands on this sheet is what a marketing team reviews: what the
     * post says, why it says it, what it points at, and whether the claims
     * in it are actually on the website. See lib/webcontent.php. */
    'webcontent' => [
        'label'    => 'Website Content',
        'title'    => 'WEBSITE CONTENT',
        'subtitle' => 'One website in; a reviewed, scheduled social calendar out',
        'table'    => 'web_content',
        'prefix'   => 'WC-',
        'pad'      => 4,
        'id_field' => 'wc_id',
        'brand'    => 'brand_name',
        'bu'       => null,
        'columns'  => [
            ['Content ID',        'wc_id',              'id'],

            // Where it came from, so any claim can be walked back.
            ['Source Page',       'source_url',         'url'],
            ['Page Type',         'source_page_type',   'text'],
            ['Source Title',      'source_title',       'text'],

            // Who the brand is, as the site describes itself.
            ['Brand',             'brand_name',         'text'],
            ['Brand Voice',       'brand_voice',        'text'],
            ['Target Audience',   'target_audience',    'text'],
            ['Audience Segment',  'audience_segment',   'text'],

            // The strategy behind the post: the part worth arguing about in
            // a review, and the part a plain summary would have skipped.
            ['Focus',             'focus',              'select', ['list' => 'Content Focus']],
            ['Content Pillar',    'content_pillar',     'select', ['list' => 'Content Pillar']],
            ['Topic',             'content_topic',      'text'],
            ['Angle',             'content_angle',      'select', ['list' => 'Content Angle']],
            ['Objective',         'content_objective',  'select', ['list' => 'Content Objective']],
            ['Funnel Stage',      'funnel_stage',       'select', ['list' => 'Funnel Stage']],
            ['Content Type',      'content_type',       'select', ['list' => 'Content Type']],
            ['Platform',          'platform',           'select', ['list' => 'Social Platform']],

            // What kind of LinkedIn post this is, and what the kinds need.
            // A poll needs its question and choices; a carousel its slides,
            // one per line as "Title — what the slide says". See
            // lib/social.php for how each kind is published.
            ['LinkedIn Post Type','linkedin_type',      'select', ['list' => 'LinkedIn Post Type']],
            ['Poll Question',     'poll_question',      'text'],
            ['Poll Options',      'poll_options',       'text'],
            ['Poll Duration',     'poll_duration',      'select', ['list' => 'Poll Duration']],
            ['Carousel Slides',   'carousel_slides',    'long'],
            ['Carousel File',     'document_asset',     'text'],
            ['Your Files',        'post_media',         'long'],

            // What actually gets posted.
            ['LinkedIn Hook',     'linkedin_headline',  'text'],
            ['LinkedIn Copy',     'linkedin_copy',      'long'],
            ['Instagram Hook',    'instagram_hook',     'text'],
            ['Instagram Copy',    'instagram_copy',     'long'],
            ['Hashtags',          'hashtags',           'text'],

            // The message in pieces, so a reviewer can see the argument.
            ['Primary Message',   'primary_message',    'long'],
            ['Key Benefit',       'key_benefit',        'text'],
            ['Pain Point',        'pain_point',         'text'],
            ['Solution',          'solution',           'text'],
            ['Proof Point',       'proof_point',        'text'],
            ['Statistic',         'statistic',          'text'],

            ['CTA',               'cta',                'select', ['list' => 'CTA']],
            ['CTA Link',          'cta_url',            'url'],

            // The creative brief, and what came of it.
            ['Graphic Headline',  'headline',           'text'],
            ['Supporting Text',   'supporting_text',    'text'],
            ['Visual Concept',    'visual_concept',     'long'],
            ['Design Style',      'design_style',       'text'],
            ['Image Prompt',      'image_prompt',       'long'],
            ['Alt Text',          'alt_text',           'text'],
            ['Graphic',           'media_asset',        'text'],

            // Whether anybody should trust it. Claim Check is the verdict of
            // fabo_claims_check(), which compares every number and every
            // credential in the copy against the website's own words.
            ['Claim Check',       'hallucination_check','text'],
            ['Needs Review',      'human_review_required', 'checkbox'],
            ['Source Verified',   'source_verified',    'checkbox'],
            ['Generation Notes',  'generation_notes',   'long'],

            // When it goes out, and what happened when it did.
            ['Scheduled Date',    'scheduled_date',     'date'],
            ['Scheduled Time',    'scheduled_time',     'text'],
            ['Status',            'status',             'select', ['list' => 'Web Content Status']],
            ['Published At',      'published_at',       'text'],
            ['Post Link',         'platform_post_url',  'url'],
            ['Publishing Note',   'publishing_status',  'text'],

            ['Remarks',           'remarks',            'long'],
            ['Send for Approval', 'approval_to',        'select', ['list' => '@clients']],
            ['Approval',          'approval_state',     'computed', ['calc' => 'approval']],
        ],
        // Nothing goes out unless it is approved: by a person, or -- with
        // "Post automatically" on -- by the claim check. See lib/approvals.php.
        'approval'    => true,
        'date_driver' => 'scheduled_date',
    ],

    'lists' => [
        'label'    => 'Lists',
        'title'    => 'REFERENCE LISTS',
        'subtitle' => 'Used for dropdowns across the portal — adding a value here needs no code change',
        'table'    => 'lists',
        'prefix'   => null,
        'pad'      => 0,
        'id_field' => null,
        'brand'    => null,
        'bu'       => null,
        'admin_only' => true,
        'columns'  => [
            ['List Name',  'list_name',  'text'],
            ['Value',      'value',      'text'],
            ['Sort Order', 'sort_order', 'int'],
            ['Active',     'active',     'int'],
        ],
        'date_driver' => null,
    ],

    ];

    /* Sheets built in the portal, merged in as equals. Same shape, so the
       grid, the export and the permissions know no difference. */
    if (function_exists('fabo_custom_sheet_defs')) {
        try {
            $sheets += fabo_custom_sheet_defs($owner);
        } catch (Throwable $e) {
            error_log('FABO custom sheets: ' . $e->getMessage());
        }
    }

    /* Columns added to a shipped sheet, appended to the end of it. Added
       here rather than written into the definitions above, because the
       definitions are the product and these are one business's additions —
       and because a portal update must not quietly lose them. */
    if (function_exists('fabo_sheet_extra_defs')) {
        try {
            foreach (fabo_sheet_extra_defs($owner) as $key => $extras) {
                if (!isset($sheets[$key]) || !empty($sheets[$key]['custom'])) {
                    continue;       // a built sheet keeps its own columns
                }
                foreach ($extras as $col) {
                    $sheets[$key]['columns'][] = $col;
                }
            }
        } catch (Throwable $e) {
            error_log('FABO sheet extras: ' . $e->getMessage());
        }
    }
    /* Every sheet keeps its files the same way: pictures in an Images
       column, shown as a gallery in the row, and everything -- documents and
       pictures alike -- behind the row's paperclip. Files added on a shared
       sheet land in exactly those two places, so nothing anybody attaches
       is ever somewhere the team cannot see it. */
    foreach ($sheets as $key => $s) {
        /* Not lists, and not posts, which carry their own media. */
        if (in_array($key, ['lists', 'webcontent', 'dashboard'], true) || !empty($s['admin_only'])) {
            continue;
        }
        $fields  = array_column($s['columns'], 2, 1);
        if (!in_array('images', $fields, true)) {
            $sheets[$key]['columns'][] = ['Images', isset($fields['row_images']) ? 'row_gallery' : 'row_images',
                                          'images'];
        }
        if (empty($s['assets_label'])) {
            $sheets[$key]['assets_label'] = 'files';
        }
    }
    return $cache[$ck] = $sheets;
}

/** Sheet keys in the canonical order used by nav and the multi-tab export. */
function fabo_sheet_order(): array
{
    $order = ['dashboard', 'master', 'store', 'calendar', 'content',
              'website', 'design', 'campaigns', 'influencers', 'btl', 'events',
              'requisitions', 'webcontent'];
    // Built sheets come after the shipped ones, in the order they were made.
    if (function_exists('fabo_custom_sheet_defs')) {
        try {
            foreach (array_keys(fabo_custom_sheet_defs()) as $k) {
                if (!in_array($k, $order, true)) { $order[] = $k; }
            }
        } catch (Throwable $e) {
            error_log('FABO sheet order: ' . $e->getMessage());
        }
    }
    return $order;
}

/** The Dashboard is a sheet for access purposes but has no editable grid. */
function fabo_sheet_label(string $key): string
{
    if ($key === 'dashboard') {
        return 'Dashboard';
    }
    $s = fabo_sheet($key);          // renames applied
    return $s['label'] ?? $key;
}

/**
 * Column-label overrides, keyed "sheet.field" => label. Admin renames a column
 * on the Columns page; the definition here stays the default it can reset to.
 * Defined in db.php (needs the database); absent or failing, nothing changes.
 */
function fabo_label_overrides(): array
{
    if (!function_exists('fabo_load_label_overrides')) {
        return [];
    }
    try {
        return fabo_load_label_overrides();
    } catch (Throwable $e) {
        return [];
    }
}

/** A sheet with any admin renames applied. $raw = the shipped definition. */
/**
 * The columns somebody has taken off a sheet, as [field => true].
 *
 * Cached for the request: fabo_sheet() is called from everywhere, and this
 * would otherwise be a query per call on a page that draws a hundred rows.
 */
function fabo_hidden_columns(string $key): array
{
    static $byOwner = [];
    require_once __DIR__ . '/lists.php';
    $owner = fabo_view_owner();
    if (isset($byOwner[$owner][$key])) {
        return $byOwner[$owner][$key];
    }
    $out = [];
    try {
        /* Ours and theirs together: a column we have taken off is off for
           everybody, and an organisation may take off more of its own. */
        foreach (fabo_all('SELECT field FROM column_hidden
                            WHERE sheet_key = ? AND client_id IN (0, ?)',
                          'si', [$key, $owner]) as $r) {
            $out[(string) $r['field']] = true;
        }
    } catch (Throwable $e) {
        // No table yet. Nothing is hidden, which is how it used to be.
    }
    $byOwner[$owner][$key] = $out;
    return $out;
}

/**
 * The order somebody has dragged this sheet's columns into.
 *
 * Returns [field => position]. A field that is absent has never been moved.
 * Cached for the request for the same reason fabo_hidden_columns() is: this
 * is asked once per fabo_sheet() call, and that is asked from everywhere.
 */
function fabo_column_order(string $key): array
{
    static $byOwner = [];
    require_once __DIR__ . '/lists.php';
    $owner = fabo_view_owner();
    if (isset($byOwner[$owner][$key])) {
        return $byOwner[$owner][$key];
    }
    $out = [];
    try {
        /* An organisation's own arrangement wins over the one we set: ours is
           ordered first so theirs overwrites it as the loop goes on. */
        foreach (fabo_all('SELECT field, position FROM column_order
                            WHERE sheet_key = ? AND client_id IN (0, ?)
                            ORDER BY client_id ASC',
                          'si', [$key, $owner]) as $r) {
            $out[(string) $r['field']] = (int) $r['position'];
        }
    } catch (Throwable $e) {
        // No table yet. Nothing has been moved, which is how it used to be.
    }
    $byOwner[$owner][$key] = $out;
    return $out;
}

/**
 * Save the order a sheet's columns were dragged into.
 *
 * $fields is every column of the sheet, in the order wanted. Whatever is not
 * named keeps no saved place, so it falls to the end in its defined order --
 * which is what should happen to a column added by a later update.
 */
function fabo_save_column_order(string $key, array $fields, int $owner = 0): void
{
    $fields = array_values(array_unique(array_filter(array_map('strval', $fields),
        static fn($f) => $f !== '')));
    if (!$fields) {
        return;
    }
    /* Replaced wholesale rather than patched: the browser sends the entire
       order, and half an old arrangement mixed with half a new one is an
       order nobody chose. */
    fabo_exec('DELETE FROM column_order WHERE sheet_key = ? AND client_id = ?',
              'si', [$key, $owner]);
    $pos = 0;
    foreach ($fields as $f) {
        $pos += 10;
        fabo_exec('INSERT INTO column_order (sheet_key, field, position, client_id)
                   VALUES (?,?,?,?)
                   ON DUPLICATE KEY UPDATE position = VALUES(position)',
                  'ssii', [$key, mb_substr($f, 0, 64), $pos, $owner]);
    }
    fabo_schema_generation(true);
}

/** Forget an arrangement, putting the columns back as they were defined. */
function fabo_reset_column_order(string $key, int $owner = 0): void
{
    fabo_exec('DELETE FROM column_order WHERE sheet_key = ? AND client_id = ?',
              'si', [$key, $owner]);
    fabo_schema_generation(true);
}

/** Hide a column, or put it back. */
function fabo_hide_column(string $key, string $field, bool $hide, int $owner = 0): void
{
    if ($hide) {
        fabo_exec('INSERT IGNORE INTO column_hidden (sheet_key, field, client_id)
                   VALUES (?,?,?)', 'ssi', [$key, $field, $owner]);
    } else {
        fabo_exec('DELETE FROM column_hidden WHERE sheet_key = ? AND field = ? AND client_id = ?',
                  'ssi', [$key, $field, $owner]);
    }
    fabo_schema_generation(true);
}

function fabo_sheet(string $key, bool $raw = false): ?array
{
    $s = fabo_sheets();
    if (!isset($s[$key])) {
        return null;
    }
    $sheet = $s[$key];
    if ($raw) {
        return $sheet;
    }
    $ov = fabo_label_overrides();
    foreach ($sheet['columns'] as $i => $c) {
        $k = $key . '.' . $c[1];
        if (isset($ov[$k]) && $ov[$k] !== '') {
            $sheet['columns'][$i][0] = $ov[$k];
        }
    }

    /* Columns taken off this sheet. The id column is never hidden — without
       it a row has nothing to identify it by, and the grid, the exports and
       every link into a row are built on it. */
    $gone = fabo_hidden_columns($key);
    if ($gone) {
        $sheet['columns'] = array_values(array_filter($sheet['columns'],
            static fn($c) => ($c[2] ?? '') === 'id' || !isset($gone[$c[1]])));
    }
    /* The order they have been dragged into.
     *
     * The id column is pinned first however the rest are arranged: it is the
     * row's identity, the grid's first cell, and the thing every export and
     * link is keyed on. Everything else sorts by its saved place, and a
     * column with no saved place falls in after the arranged ones, keeping
     * its defined order -- so a column a later update adds turns up at the
     * end rather than in the middle of somebody's arrangement.
     */
    $order = fabo_column_order($key);
    if (!$order && $key === 'events') {
        /* Never arranged: Events reads in its recommended order (what the
           event is, when, where and who, the programme, money, approvals,
           then each result beside its target) rather than the order the
           columns happened to be defined in. See lib/eventstages.php. */
        require_once __DIR__ . '/eventstages.php';
        foreach (fabo_evstage_resolve_order($sheet['columns']) as $i => $f) {
            $order[$f] = ($i + 1) * 10;
        }
    }
    if ($order) {
        $ranked = [];
        foreach ($sheet['columns'] as $i => $c) {
            $ranked[] = [
                ($c[2] ?? '') === 'id'
                    ? fabo_id_rank($key, $order, $c[1]) // first, but see there
                    : ($order[$c[1]] ?? 1000000 + $i),  // unarranged: after, in order
                $i,
                $c,
            ];
        }
        /* The defined position breaks ties, so the result does not depend on
           the sort being stable. */
        usort($ranked, static fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $sheet['columns'] = array_column($ranked, 2);
    }

    // What this organisation calls the sheet itself, if they renamed it.
    if (function_exists('fabo_sheet_name_overrides')) {
        try {
            $names = fabo_sheet_name_overrides();
            if (!empty($names[$key]['label'])) {
                $sheet['label'] = $names[$key]['label'];
                $sheet['title'] = strtoupper($names[$key]['label']);
            }
            if (isset($names[$key]['subtitle']) && $names[$key]['subtitle'] !== '') {
                $sheet['subtitle'] = $names[$key]['subtitle'];
            }
        } catch (Throwable $e) {
            error_log('FABO sheet names: ' . $e->getMessage());
        }
    }
    return $sheet;
}

/**
 * Where the id column ranks in a sheet's saved order.
 *
 * First, always -- except on Events, whose agreed order puts Committee
 * Approval and Hub / Spoke ahead of the Event ID. There the id keeps the
 * place it was saved at; and when an order was saved without it (the Columns
 * tab used to leave the id out), it sits straight before the Event Name, so
 * the two still read together.
 */
function fabo_id_rank(string $key, array $order, string $field): int
{
    if ($key !== 'events') {
        return -1;
    }
    if (isset($order[$field])) {
        return (int) $order[$field];
    }
    return isset($order['event_name']) ? (int) $order['event_name'] - 1 : -1;
}

/** Every sheet, renames applied. */
function fabo_sheets_labelled(): array
{
    $out = [];
    foreach (array_keys(fabo_sheets()) as $k) {
        $out[$k] = fabo_sheet($k);
    }
    return $out;
}

/**
 * Is this cell still shut on this row?
 *
 * A column may carry ['unlock' => 'some_date']: it cannot be written until
 * that date on the row has passed. Achieved figures work this way, because a
 * result typed while the event is still running is a guess, and a guess in a
 * results column is worse than an empty cell — somebody will report it.
 *
 * A row with no date in that column stays shut: there is no way to know the
 * event is over, so the honest answer is "not yet".
 *
 * The grid renders from this and api/save.php enforces it. One function, so
 * the two cannot come to different conclusions.
 */
function fabo_cell_locked(array $col, array $row): bool
{
    $on = $col[3]['unlock'] ?? '';
    if ($on === '') {
        return false;
    }
    $when = trim((string) ($row[$on] ?? ''));
    if ($when === '') {
        return true;
    }
    return strtotime($when) > strtotime(fabo_today());
}

/**
 * The same question, for the portal's own grid and its save endpoint.
 *
 * One difference: a one-day event is often entered with a Start Date and no
 * End Date, and fabo_cell_locked() keeps its results shut forever -- so an
 * event long over could never have its Achieved figures recorded. Here the
 * Start Date stands in for a missing End Date. Kept as its own function so
 * the shared live sheets, which ask fabo_cell_locked(), behave exactly as
 * they did.
 */
function fabo_grid_cell_locked(array $col, array $row): bool
{
    $on = $col[3]['unlock'] ?? '';
    if ($on === 'end_date' && trim((string) ($row['end_date'] ?? '')) === ''
        && trim((string) ($row['start_date'] ?? '')) !== '') {
        $row['end_date'] = $row['start_date'];
    }
    return fabo_cell_locked($col, $row);
}

/** What to say about a shut cell on the grid; see fabo_grid_cell_locked(). */
function fabo_grid_cell_lock_note(array $col, array $row): string
{
    if (($col[3]['unlock'] ?? '') === 'end_date' && trim((string) ($row['end_date'] ?? '')) === ''
        && trim((string) ($row['start_date'] ?? '')) !== '') {
        $row['end_date'] = $row['start_date'];
    }
    return fabo_cell_lock_note($col, $row);
}

/**
 * Is this column kept by the portal rather than typed into?
 *
 * Different from fabo_cell_locked(), which shuts a cell until a date passes.
 * This one never opens: the value is worked out from somewhere else, and a
 * person editing it would only be overwritten by the next recalculation.
 */
function fabo_col_kept(array $col): string
{
    return (string) ($col[3]['kept'] ?? '');
}

/** What to say about a shut cell, in a tooltip. */
function fabo_cell_lock_note(array $col, array $row): string
{
    $on   = $col[3]['unlock'] ?? '';
    $when = trim((string) ($row[$on] ?? ''));
    return $when === ''
        ? 'Opens once this row has an end date and that date has passed.'
        : 'Opens on ' . date('d M Y', strtotime($when)) . ', when the event has finished.';
}

/** Every column's field name for a sheet, in order. */
function fabo_fields(array $sheet): array
{
    return array_map(static fn($c) => $c[1], $sheet['columns']);
}

/** Only the columns a user may type into. */
function fabo_editable_fields(array $sheet): array
{
    $out = [];
    foreach ($sheet['columns'] as $c) {
        // An upload column is not edited as a value: api/cellfile.php owns it,
        // because setting it means storing a file as well as a number.
        if ($c[2] !== 'computed' && $c[2] !== 'id'
            && $c[2] !== 'file' && $c[2] !== 'image' && $c[2] !== 'images') {
            $out[$c[1]] = $c[2];
        }
    }
    return $out;
}
