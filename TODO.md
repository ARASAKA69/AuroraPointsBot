# Aurora Points Bot - To-Do & Feature Checklist

## 🚀 Core Bot & Implemented Features - All Systems Go! ✅

- [x] **Foundation & Setup:**
    - [x] Webhook Processing
    - [x] Secure Credentials Management (`ap_bot_credentials.php`)
    - [x] Database Helpers (`db_helpers.php`) & Connection
    - [x] Robust Logging (`logMessage` via config)
- [x] **User Management:**
    - [x] `ensureUserExists()` - Auto-registration/update on activity.
    - [x] `getUserDetails()` - Fetching user data.
- [x] **Points System:**
    - [x] `addPoints()` - Adding points for various reasons.
    - [x] `removePointsDb()` - Deducting points.
    - [x] `transferPoints()` - For gifting AP.
    - [x] Transaction Logging (integrated into points functions).
- [x] **Activity & Engagement Rewards:**
    - [x] Points for General Activity (in `handleRegularMessage`).
    - [x] Points for "Thank You" (in `handleRegularMessage` with word boundary).
- [x] **Withdrawal System:**
    * [x] `createWithdrawalRequest()`, `getWithdrawalRequest()`, `updateWithdrawalStatus()`, `getPendingWithdrawalByUserId()`
    * [x] Admin approval/rejection via Inline Keyboard & Callbacks (`handleWithdrawalCallback`).
- [x] **Admin Permissions:**
    * [x] `isUserGroupAdmin()` - Checking if user is group admin/creator.
    * [x] Main `ADMIN_USER_ID` override.
- [x] **Implemented User Commands:**
    * [x] `/apwelcome` - Welcome message.
    * [x] `/apinfo` - User's own AP info (also admin usage for others).
    * [x] `/aphelp` - Nicely formatted help message.
    * [x] `/apgift <amount> <user_id|reply>` - Gift AP to others.
    * [x] `/ap_leaderboard` - Show top AP earners.
    * [x] `/ap_history` - User's own AP transaction history (also admin usage for others).
    * [x] `/ap_daily_reward` - Claim daily AP bonus.
    * [x] `/apwithdraw` - Request AP withdrawal for subscription.
- [x] **Implemented Admin Commands:**
    * [x] `/apsend <amount> <user_id|reply>` - Grant AP.
    * [x] `/apremove <amount> <user_id|reply>` - Deduct AP.
    * [x] `/apuserinfo <user_id|reply>` - Get detailed user summary (DM to admin).

---

## 💡 Future Feature Ideas - "The Wishlist" 🤩
*(Let's get creative! No bad ideas in a brainstorm!)*

* **Mini-Games - Let the Good Times Roll! 🎲**
    * [ ] `/ap_flip_coin <amount>`: Feeling lucky? Flip a coin for AP! Heads you win, tails... well, you know.
        * Tasks: Handler, betting logic, randomizer, point updates.
    * [ ] `/ap_dice_roll <amount> [guess]`: Roll a dice, maybe even guess the outcome for a bigger multiplier!
        * Tasks: Handler, more complex betting, dice roll logic, point updates.

* **Achievements & Badges - Collect 'Em All! 🎖️**
    * [ ] Define a list of cool achievements (e.g., "Point Pioneer," "Generous Duke/Duchess," "Daily Devotee," "Topic Titan").
    * [ ] Database: New table `user_achievements` or extend `users`.
    * [ ] Logic: Integrate checks in various functions to award achievements + bonus AP.
    * [ ] `/ap_badges` or `/ap_achievements`: Command to show off a user's bling.

* **AP Lottery/Raffle - Big Wins! 🎟️**
    * [ ] `/ap_buy_ticket [count]`: Users spend AP for a shot at a big prize.
    * [ ] `/ap_lottery_info`: Show the current jackpot, tickets in play, time 'til the big draw.
    * [ ] Admin command: `/ap_draw_lottery`: Make someone's day!
    * [ ] Database: Tables for `lotteries` and `lottery_tickets`.

* **Community Power-Ups - For the People! 🚀**
    * [ ] `/ap_suggest_feature <your_awesome_idea>`: Let users tell what they want for Aurora Horizons or the bot!
        * Tasks: Store suggestions (DB table `suggestions`), maybe an admin command to review.
    * [ ] `/ap_kudos @user <reason_for_being_awesome>`: A way to give public props. Maybe a separate "kudos count" or just a nice announcement.
        * Tasks: Handler, maybe a `kudos_log` table or extend `users` table.

* **Super Admin Tools - For Admins Eyes Only! 🕶️**
    * [ ] `/ap_stats`: "Bot, how we doin'?" - Getting some cool stats (total users, total AP distributed, etc.).
    * [ ] `/ap_set_multiplier <value> [duration_in_hours]`: "It's raining AP!" - Temporarily boost AP earnings for activity.
        * Tasks: Store multiplier & expiry in config or DB. Adjusting `addPoints` for activity.

