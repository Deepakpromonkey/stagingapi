import io, csv, sys

src = io.open('docs/carrier-vet-queries.sql', encoding='utf-8').read()
bar = '-- ' + '-' * 76
parts = src.split(bar)

blocks = {}
for i in range(1, len(parts) - 1, 2):
    label = parts[i].strip().splitlines()[0].replace('--', '').strip().split('—')[0].strip()
    blocks[label] = parts[i + 1].strip('\n')

# A4 is the one block that spans both servers: the comparison runs on the feed,
# the onboarding-status tail on the tenant database. Split it so each half ends
# up in the right SQL column.
_a4 = blocks['A4']
_cut = _a4.index('-- A4-onboarding')
blocks['A4'], blocks['A4-onboarding'] = _a4[:_cut].rstrip(), _a4[_cut:].strip()

FEED = 'carrier (MySQL 8.4)'
TEN  = 'newbrokerapi (MariaDB 10.4)'
BOTH = 'carrier + newbrokerapi'

SETNAMES = {
    FEED: "SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;",
    TEN:  "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;",
    BOTH: "-- feed queries: SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;\n-- tenant queries: SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;",
}

# (section, question, intent, resolver, params, database, [blocks], answer_shape)
ROWS = [
 ('A1','Vet MC246530','Full vet: verdict + score + factors','MC docket number -> DOT','@q=\'MC246530\'; then @dot, @company_id',BOTH,['A0','A1','A1b','A1c'],
  'Verdict line (PASS/REVIEW/HARD-STOP) + DT Score + band, then authority, insurance, safety, OOS, years active, fraud-signal count, factors passed n/20, freshness stamp.'),
 ('A1','Run a vet on Jeffory Allen Rundlett','Full vet, entity named by person/company name','Legal name -> DOT (disambiguate first if >1 match)','@name; @q; @dot; @company_id',BOTH,['A3','A0','A1','A1b','A1c'],
  'If A3 returns >1 match, ask which one before running A1 (GR-14). Otherwise same as "Vet MC246530".'),
 ('A1','Is DOT 1749 good to use?','Booking-adjacent suitability question','Bare DOT number','@q=\'1749\'; @dot=1749; @company_id',BOTH,['A0','A1','A1b','A1c'],
  'Never answered "yes". Answer "No hard-stops. DT Score {n}. {top 2 risks}. Decision is yours." (GR-2, GR-10). verdict + verdict_reasons carry the gate.'),
 ('A1','Check sandriano16@gmail.com','Full vet, entity named by email','Email -> DOT (A0 email branch)','@q=\'sandriano16@gmail.com\'; @dot; @company_id',BOTH,['A0','A1','A1b','A1c'],
  'A0 resolves the email to a DOT. If the email is shared across carriers, A2 "Shared email" flags it and the answer must say so before the vet.'),
 ('A1','Can I put a load on MC146672?','Booking-adjacent suitability question','MC docket number -> DOT','@q=\'MC146672\'; @dot; @company_id',BOTH,['A0','A1','A1b','A1c'],
  'Lead with the gate (GR-9). If verdict = HARD-STOP: "HARD-STOP — do not book. {name} (MC/DOT): {verdict_reasons}. DT Score {n}. Auto-blocked from booking per your rules."'),
 ('A1','Quick check on Norbet Trucking','Full vet, entity named by company name','Company name -> DOT','@name=\'Norbet\'; @dot; @company_id',BOTH,['A3','A0','A1','A1b','A1c'],
  'Same as the name-based vet: disambiguate via A3 first.'),

 ('A2','Is this MC a scam?','Fraud / identity signal report','MC or DOT already in context','@dot',FEED,['A2'],
  'Never "yes it is a scam". Answer "X of {checks_total} identity/fraud checks failed: ..." (GR-4). checks_failed / checks_total ride on every row.'),
 ('A2','This carrier feels off, check them','Fraud / identity signal report','DOT already in context','@dot',FEED,['A2'],
  'Same as above. List the FLAGGED rows with their detail_count (how many other carriers share the identifier).'),
 ('A2','Are they real?','Fraud / identity signal report','DOT already in context','@dot',FEED,['A2'],
  'Identity checks passed/failed, not a verdict on existence.'),

 ('A3','Pull up Rundlett','Name search / disambiguation','Free-text name','@name=\'RUNDLETT\'',FEED,['A3'],
  'If >1 match: table of matches (name, MC/DOT, state, authority, DT Score) then "Which one?". If exactly 1: profile summary card. No action until resolved (GR-14).'),
 ('A3','Find South Park Motor','Name search / disambiguation','Free-text name','@name=\'SOUTH PARK MOTOR\'',FEED,['A3'],
  'Same. DT Score per match needs a second pass on A1c — the two databases are on different servers.'),

 ('A4','Compare MC264180 vs MC146672','Side-by-side comparison','Two MC numbers -> DOTs','@q per MC; @dots=\'dot1,dot2\'; @company_id',BOTH,['A0','A4','A4-onboarding'],
  'Side-by-side table: DT Score, authority, insurance limits + expiry, safety rating, OOS %, crashes, fleet size, years active, fraud signals, onboarding status.'),
 ('A4','Which is better, Rinella or South Park?','Side-by-side comparison, names not numbers','Two names -> DOTs via A3','@name x2; @dots; @company_id',BOTH,['A3','A4','A4-onboarding'],
  'Close with "By your current weights, {X} scores higher ({n} vs {m}). Key difference: {factor}." "Better" is score-relative to the broker weights and must be stated as such (GR-10).'),

 ('A5','Who did I vet today?','Vet history, today','n/a','@company_id',TEN,['A5'],
  'Table: carrier, MC/DOT, DT Score, vetted date, by whom. Tenant-wide, not just the asking user (GR-6).'),
 ('A5',"Show this week's vets",'Vet history, this week','n/a','@company_id',TEN,['A5'],
  'Swap the WHERE clause for the commented "this week" line inside A5.'),
 ('A5','Did anyone on my team vet Norbet already?','Vet history for one carrier','Name -> DOT','@name; @dot; @company_id',BOTH,['A3','A5'],
  'A5b (inside the A5 block) returns who vetted it, the score, and when — across the whole tenant.'),

 ('A6','Vet these: MC264180, MC146672, MC246530, MC1102425, MC136530','Bulk vet','MC list -> DOT list','@mc_list',FEED,['A6'],
  'Result table with hard-stops flagged first. Over ~10 carriers, run as a T2 batch job against the target list rather than inline.'),

 ('B1','Does MC246530 have active authority?','Authority status','MC -> DOT','@q; @dot',FEED,['A0','B1'],
  '"{name}: Common — {status}, Contract — {status}, Broker — {status}. Operation: {type}. Authority history: {uninterrupted|interrupted, revocations in last 36 months: n}. Source: FMCSA sync {date}." If revoked/inactive and the question is booking-adjacent, use the GR-9 gate framing.'),
 ('B1','Is their broker authority active?','Authority status, broker leg only','DOT in context','@dot',FEED,['B1'],
  'Read broker_authority. Note: carrier + broker authority together is a double-broker risk and shows in A2.'),
 ('B1','What operation type are they?','Operation classification','DOT in context','@dot',FEED,['B1'],
  'operation column: Interstate / Intrastate Hazmat / Intrastate Non-Hazmat.'),

 ('B2','How long have they been in business?','Age of authority','DOT in context','@dot',FEED,['B2'],
  'years_active, authority_granted, five_plus_years flag.'),
 ('B2','Any revocations?','Revocation history','DOT in context','@dot',FEED,['B2','B1'],
  'B2 gives the counts; B1b gives the event log. A revocation later discontinued is not a live revocation — read the outcome column before quoting original_action_desc.'),
 ('B2','Is their authority new?','Authority age, gate-relevant','DOT in context','@dot',FEED,['B2'],
  'authority_under_90_days is the gate-relevant flag; under 90 days is a hard-stop in A1.'),

 ('B3',"What's Rundlett's dispatch number?",'Contact info','Name -> DOT','@name; @dot',FEED,['A3','B3'],
  'Dispatch phone, plus the identity note: "Phone/email unique to this carrier: yes/no." A shared phone is a fraud signal the broker must see before calling (GR-7).'),
 ('B3','Email for MC246530?','Contact info','MC -> DOT','@q; @dot',FEED,['A0','B3'],
  'official_email + other_carriers_same_email. B3b lists who else uses it.'),
 ('B3','Where are they based?','Contact info / address','DOT in context','@dot',FEED,['B3'],
  'physical_address + mailing_address, with address_looks_virtual and other_carriers_same_address attached.'),

 ('B4',"What's their DOT?",'Identifiers','DOT in context','@dot',FEED,['B4'],
  'MC, DOT, EIN (as on the FMCSA record), DUNS.'),
 ('B4','EIN for Sikander S Gill?','Identifiers, EIN','Name -> DOT','@name; @dot',FEED,['A3','B4'],
  'EIN is not carried in the FMCSA feed — B4 returns "NA — not carried in the FMCSA feed" rather than omitting it silently.'),
 ('B4','DUNS?','Identifiers, DUNS','DOT in context','@dot',FEED,['B4'],
  'duns column, or NA. A DUNS shared with other carriers is a fraud signal (A2).'),

 ('B5','Do they haul general freight?','Cargo registration','DOT in context','@dot',FEED,['B5'],
  '"Registered to carry: ... Not registered: ..." plus the caveat "Cargo registrations are self-reported to FMCSA." Answers the registration fact, not a capability endorsement.'),
 ('B5','Are they authorized for hire?','Operation classification','DOT in context','@dot',FEED,['B5'],
  'classifications column. NULL when the census file has no row for this DOT yet — report as unknown, not as "no".'),
 ('B5','Can they haul grain?','Cargo registration, one commodity','DOT in context','@dot; @cargo_column=\'crgo_grainfeed\'',FEED,['B5'],
  'B5b (inside the B5 block) gives Registered / Not registered for one commodity. Same self-reported caveat applies.'),

 ('B6','Is Warrior Trucking the same as Sikander S Gill Corp?','Legal entity / DBA mapping','Two names','@dot; @name_a; @name_b',FEED,['B6'],
  'Legal name <-> DBA mapping from the record. B6b puts both names side by side: same DOT on both rows means same entity.'),
]

# Which server each block runs on. A row that needs both gets two SQL columns,
# because one script cannot span two servers.
BLOCK_DB = {
    'A0': FEED, 'A1': FEED, 'A1b': FEED, 'A2': FEED, 'A3': FEED, 'A4': FEED, 'A6': FEED,
    'B1': FEED, 'B2': FEED, 'B3': FEED, 'B4': FEED, 'B5': FEED, 'B6': FEED,
    'A1c': TEN, 'A5': TEN, 'A4-onboarding': TEN,
}

def script(blks, db):
    picked = [b for b in blks if BLOCK_DB[b] == db]
    if not picked:
        return ''
    return SETNAMES[db] + '\n\n' + '\n\n'.join(
        '-- ===== %s =====\n%s' % (b, blocks[b].strip()) for b in picked)

out = 'docs/carrier-vet-questions.csv'
with io.open(out, 'w', encoding='utf-8', newline='') as fh:
    w = csv.writer(fh, quoting=csv.QUOTE_ALL, lineterminator='\n')
    w.writerow(['id', 'section', 'user_question', 'intent', 'resolver',
                'parameters', 'database', 'sql_blocks', 'answer_shape',
                'sql_carrier_db', 'sql_newbrokerapi_db'])
    for n, (sec, q, intent, resolver, params, db, blks, shape) in enumerate(ROWS, 1):
        missing = [b for b in blks if b not in blocks]
        if missing:
            sys.exit('unknown blocks: %r' % missing)
        w.writerow([n, sec, q, intent, resolver, params, db, ' + '.join(blks), shape,
                    script(blks, FEED), script(blks, TEN)])

print('wrote %s: %d rows' % (out, len(ROWS)))
