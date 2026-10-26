<?php

/**
 * The messages of the nine rules for Articles 20, 18 and 1.
 *
 * WHY THEY LIVE HERE AND NOT IN THE CLASS
 * ---------------------------------------
 * When they were written inside the rules they had three problems that moving
 * them to a file does not fix; only changing them does:
 *
 * 1. They had no English version. The rule is the same in both languages; only
 *    the text was bound to the language of whoever reads the code.
 * 2. They had no accents. "articulo", "esta", "podria". Written by hand it shows
 *    that the formal register was not maintained, and that is precisely what
 *    users notice when they read a legal notice.
 * 3. They contained technical detail. Four of them ended with "Run php artisan
 *    ipc:sync", which is the console instruction for whoever administers the
 *    system leaking into a message a tenant may read. That was split off: the
 *    technical detail stays in the service, where an operator reads it, and
 *    only what the rule actually says remains here.
 *
 * THE REGISTER
 * ------------
 * Uniform second person formal ("usted"), which is what a text addressed to
 * someone administering a lease of theirs calls for. No emphatic capitals, no
 * "Mind that:", no diminutives. Imperatives read as "Mark", "Select", "Record",
 * "Correct", which is how a legal form asks for things.
 *
 * THE SHAPE OF EACH MESSAGE
 * -------------------------
 * A message explains the situation and, when the user can act, says what the
 * action is. The article is appended separately by whoever assembles the final
 * text, so the same string serves both the internal panel and a notification
 * where the article is already cited elsewhere.
 */
return [

    'has_ipc_data' => [
        // Without December data there is no ceiling to calculate and no letter
        // to support it. How to fetch it is the service's concern, not this
        // file's.
        'missing' => 'The December :year CPI is not on record, and it is the figure Article 20 requires for an adjustment effective :effective_from. The official figure has not yet been loaded into the system.',
        'missing_context' => 'The December :year CPI for :geography is not on record, and it is the figure Article 20 requires for an adjustment effective :effective_from. The official figure has not yet been loaded into the system.',
        'no_variation' => 'The DANE figure for December :year is on record but carries no annual variation, which is the figure Article 20 requires.',
        'no_variation_context' => 'The DANE figure for :geography in December :year is on record but carries no annual variation, which is the figure Article 20 requires.',

        // These three are for exceptions, which appear as a notification rather
        // than as a line in the checks panel. They are shorter because the
        // context is already known: the user has just requested an operation and
        // this is why it could not be carried out.
        //
        // None of them says HOW to obtain the data. See the header note.
        'missing_short' => 'The December :year CPI for :geography is not on record. Without that figure the ceiling of the adjustment cannot be calculated.',
        'missing_city' => 'A city CPI was selected without indicating which one. Select the city in the adjustment form.',
        'missing_index' => 'The CPI index for the :period period in :geography is not on record.',
    ],

    'is_residential_scope' => [
        // Article 1 limits the Act to urban housing. In commercial premises the
        // CPI is a reference, not a ceiling. This explains without alarming and
        // without assuming the classification is wrong.
        'commercial' => 'This rental is registered as commercial. Act 820 of 2003 applies to urban housing, so in commercial premises the rent is determined by the market and the calculated percentage is a reference rather than a legal ceiling. If the classification does not match the property, correct it before continuing.',
    ],

    'within_twelve_months' => [
        'due' => 'The rental completes twelve months on :due_date. This adjustment may take effect from that date without departing from Article 20.',
        'acknowledged_early' => 'This adjustment is recorded before the twelve months under a single rent have elapsed, which run until :due_date. The reason is recorded in the notes.',
        'early' => 'The last rent change took place less than twelve months ago: this adjustment may take effect from :due_date. Article 20 counts the twelve months under a single rent. Mark the confirmation if the adjustment must apply before that date and record the reason in the notes.',
    ],

    'statutory_calendar_year' => [
        'acknowledged' => 'The :chosen CPI is being applied where Article 20 fixes the :statutory CPI for this effective date. The reason is recorded in the notes.',
        'mismatch' => 'Article 20 fixes the :statutory CPI for an adjustment effective :effective_from, and the :chosen CPI has been selected. Mark the confirmation if that is the adjustment you wish to record and state the reason in the notes.',
    ],

    'city_ipc_requires_acknowledgement' => [
        'national_reference' => 'For the national total, the CPI for the year is :pct %.',
        'same_as_national' => 'The CPI of :geography is applied instead of the national total, but its percentage matches the national one (:pct %), so the result complies with article 20. It is kept as a reference of the series used.',
        'acknowledged' => 'The adjustment is calculated with the :geography CPI instead of the national total. The reason is recorded in the notes.',
        'warning' => 'The :geography CPI is being applied instead of the national total. Article 20 refers to the consumer price index without distinguishing cities, so this choice departs from its wording. Mark the confirmation if this is what you wish to record. :national_reference',
    ],

    'commercial_value_within_catastral_ceiling' => [
        'warning' => 'The declared commercial value (:commercial) exceeds twice the cadastral appraisal (:catastral), which is the ceiling Article 18 sets. The one percent of the rent would be calculated on a value the law does not admit for that purpose.',
    ],

    'within_one_percent_of_commercial_value' => [
        'warning' => 'The rent of :rent exceeds one percent of the declared commercial value of :commercial. Article 18 limits the rent as a whole to that one percent, so the adjustment would leave it :excess above the ceiling. The ceiling in this case is set by the parties and not by the CPI, so it is not corrected by lowering the percentage.',
    ],

    'within_cap_or_acknowledged' => [
        'acknowledged' => 'The increase exceeds the CPI by :excess. The reason is recorded in the notes. Note that Article 20 requires the amount and the date to be communicated, and that payment does not cure the absence of notice.',
        'warning' => 'The requested rent exceeds by :excess the figure produced by the :year CPI. Article 20 phrases this as a ceiling, but practice admits a higher increase where the parties agree and document it. Record the agreement in the notes so that the notice to the tenant reflects it.',
    ],

    'notification_has_valid_means' => [
        'valid' => 'The notice was given by :method, a means expressly recognised by Article 20. The date and the means are recorded in the letter.',
        'invalid' => 'Article 20 recognises the authorised postal service and the mechanism agreed in the contract. The means given (:method) is not among them: it is valid if the contract established that channel, and it is advisable that it be put in writing. Note that payment of the rent does not cure the absence of notice, as provided for in paragraph 3.',
    ],

];
