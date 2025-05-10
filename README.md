# Aurora Points Bot ✨

![Banner](./images/AuroraPointsBot.png)

A Telegram bot designed to enhance community engagement within the Aurora Horizons group by rewarding user activity with "Aurora Points" (AP). Users can earn AP through participation and redeem them for Aurora Horizons access.

This project is open source under the [GNU General Public License v3.0](LICENSE).

## 🌟 Purpose

The primary goal of the Aurora Points Bot is:
* To foster a more active and interactive community within the [Aurora Horizons Group Topic](https://t.me/AuroraHorizonsGroup/459140).
* To provide a way for dedicated community members to earn access to Aurora Horizons through their engagement, especially benefiting users who might not be able to purchase subscriptions directly.
* To make community participation fun and rewarding!

## 🚀 Features

The Aurora Points Bot comes packed with features for both users and administrators:

### For Users:
* **Point Earning:**
    * Automatically earn AP for general activity in the designated group topic.
    * Receive AP when another member thanks you in a reply to your message.
    * Claim a daily AP bonus with `/ap_daily_reward`.
* **Point Management & Redemption:**
    * `/apwelcome`: Get a friendly welcome and brief intro to the bot.
    * `/apinfo`: Check your current AP balance and points needed for a subscription.
    * `/aphistory`: View your recent AP transactions (details are sent via DM).
    * `/apgift <amount> <user_id|reply>`: Gift some of your AP to another member.
    * `/apwithdraw`: Request to redeem 200 AP for a 1-Week Aurora Horizons subscription (this can be done repeatedly).
* **Community & Fun:**
    * `/apleaderboard`: See who's topping the AP charts!
* **Help:**
    * `/aphelp`: Get a detailed list of all available commands and how the bot works.

### For Group Admins:
*(Commands usable by designated Group Administrators)*
* `/apsend <amount> <user_id|reply>`: Grant AP to a specific user.
* `/apremove <amount> <user_id|reply>`: Deduct AP from a specific user.
* `/apinfo <user_id>`: View the AP balance of a specific user.
* `/aphistory <user_id|reply>`: View the transaction history of a specific user (details sent to admin via DM).
* `/apuserinfo <user_id|reply>`: Get a comprehensive summary of a user's bot data, including points, recent transactions, and withdrawal history (details sent to admin via DM).
* **Withdrawal Management:** Admin Approves or reject user withdrawal requests via interactive buttons sent to the main Bot Admin's private chat.

## 🛠️ Technical Overview

* **Language:** PHP
* **Telegram API Interaction:** Uses direct cURL requests.
* **Database:** MySQL (stores user data, points, transactions, withdrawals & other).
* **Mode of Operation:** Webhook
* **Core Files:**
    * `ap_bot.php`: Main bot logic, webhook endpoint.
    * `db_helpers.php`: Functions for database interactions.
    * `ap_bot_credentials.php` hidden by default (located in a private directory): Stores sensitive information like API tokens and database credentials.

## 📜 License

This project is licensed under the **GNU General Public License v3.0**. See the `LICENSE` file for more details.

---
