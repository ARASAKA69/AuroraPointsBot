# Bot Feature Checklist (Based on your latest working code)

## Core Bot Functionality - ✅ Implemented
- [x] Basic Webhook Setup and Update Processing
- [x] Database Connection (`db_helpers.php` from your file)
- [x] User Management (`ensureUserExists`, `getUserDetails` from your file)
- [x] Points Management (`addPoints`, `removePointsDb`, `transferPoints` from your file)
- [x] Transaction Logging (within point functions in your `db_helpers.php`)
- [x] Withdrawal System (`createWithdrawalRequest`, `getWithdrawalRequest`, `updateWithdrawalStatus`, `getPendingWithdrawalByUserId` from your file)
- [x] Admin Permission Check (`isUserGroupAdmin` from your file)
- [x] Activity Points (`handleRegularMessage` from your file)
- [x] "Thank You" Points (`handleRegularMessage` with word boundary check, from your file)

## Implemented Commands - ✅
- [x] **`/apwelcome`** (User)
- [x] **`/apinfo`** (User: self; Admin: self or `<user_id>`)
- [x] **`/aphelp`** (User - with enhanced formatting, listing all implemented commands, based on your file)
- [x] **`/apsend <amount> <user_id|reply>`** (Admin)
- [x] **`/apremove <amount> <user_id|reply>`** (Admin)
- [x] **`/apwithdraw`** (User - with admin approval via DM)
- [x] **`/apgift <amount> <user_id|reply>`** (User)
- [x] **`/apleaderboard`** (User - displays `@username` or `first_name`)
- [x] **`/aphistory`** (User: self via DM; Admin: `<user_id>` or reply, result via DM with group confirmation - your version which ignores reply for self-history)
- [x] **`/ap_daily_reward`** (User - claim daily AP bonus)
- [x] **`/apuserinfo <user_id or reply>`** (Admin - detailed user summary sent via DM to admin - *You mentioned this is working in your latest code, so I'm marking it based on that.*)

---

## Future Potential Features (Brainstormed Ideas):

* **Mini-Games (User-focused):**
    * [ ] `/ap_flip_coin <amount>`: Bet AP on a coin flip.
    * [ ] `/ap_dice_roll <amount>`: Bet AP on a dice roll.
* **Achievements/Badges System (User-focused):**
    * [ ] Define specific achievements (e.g., "Point Earner," "Generous Gifter," "Daily Regular").
    * [ ] Database changes: New table for `user_achievements` or extend `users` table.
    * [ ] Logic in relevant functions to check and grant achievements/AP bonuses.
    * [ ] `/ap_badges` or `/ap_achievements` command to display earned achievements.
* **AP Lottery/Raffle (User-focused):**
    * [ ] `/ap_buy_ticket <amount_of_tickets>`: Users spend AP for tickets.
    * [ ] `/ap_lottery_info`: Shows current pot, tickets sold, time until draw.
    * [ ] Admin command like `/ap_draw_lottery`: Randomly selects winner.
    * [ ] Database changes: Table for `lottery_tickets` and `lotteries`.
* **Enhanced Admin Tools:**
    * [ ] `/ap_broadcast <message>`: (Admin) Send a message to all opted-in bot users (use with extreme caution).
    * [ ] `/ap_stats`: (Admin) Show bot usage statistics.
    * [ ] `/ap_set_multiplier <value> [duration_hours]`: (Admin) Temporarily change the points awarded for activity (e.g., during events).
* **Content Contribution / Community Features:**
    * [ ] `/ap_suggest_feature <suggestion_text>`: Users can submit suggestions. Bot stores them for admin review.
    * [ ] `/ap_kudos @user <reason>`: Publicly acknowledge a user (maybe with a non-AP "kudos point" system).

