=== Aluleth SMTP – SMTP Mailer, Email Log & Deliverability ===
Contributors: aluleth
Tags: smtp, email log, mailer, deliverability, email
Requires at least: 7.0
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 0.3.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fix WordPress emails: send them through SMTP or an email service, with a fallback, an email log, a queue, bounce handling and deliverability checks.

== Description ==

By default, WordPress sends emails with the web server's mail function. Many of them land in spam or never arrive: order receipts, password resets, form notifications. Aluleth SMTP sends every email through a real email service instead, and shows you what happened to each one.

Every service is free in Aluleth SMTP. Nothing is locked.

**What it does that a plain SMTP plugin does not**

* It follows each email after sending: delivered, bounced or marked as spam, through the service's signed webhooks, and blocks bad addresses by rules you choose (a bounced address can still get its password reset, but no more newsletters).
* It shares that blocked list with your other plugins: Aluleth Forms, Popups and the other Aluleth plugins skip the same addresses.
* It keeps your log safe: password reset links, login links and verification codes are hidden before an email is saved.
* It sends each email through the right service: receipts through one, newsletters through another, with a queue that respects your plan's sending limits.
* It checks your domain against the Gmail, Yahoo and Microsoft sender rules and gives you the DNS records to fix it.

**14 services**

SendGrid (global or EU), Amazon SES, Brevo, Mailgun (US or EU), Postmark, Resend, MailerSend, Mailjet, SMTP2GO, SparkPost (US or EU), Elastic Email, Gmail / Google Workspace, Microsoft 365 / Outlook, and any other SMTP server (your host, Zoho…). Services are reached through their API: it is faster than SMTP and works on hosts that block SMTP ports.

Gmail and Microsoft 365 send from your own mailbox: you make a small app at Google or Microsoft once (the plugin shows each step and the address to copy), then sign in. No password is stored, only an access the plugin can renew, encrypted.

Keys are checked with the service when you save, stored encrypted and never shown again. You can also keep them out of the database with constants in wp-config.php.

**A fallback that saves your emails**

Add a second connection as a fallback. When the main service refuses an email or cannot be reached, the fallback sends it right away. The overview shows how many emails the fallback saved.

**Which email goes where**

Routing rules send each email through the right connection, based on the plugin that sends it (WooCommerce, Aluleth Forms, your theme…), the kind of email (transactional or marketing) and the From address. For example: receipts through Postmark, newsletters through Amazon SES.

**A queue with sending limits**

Marketing emails can go out in the background, so visitors never wait. Set the sending limit of your plan (per second, minute, hour or day): above it, emails wait in the queue instead of being refused. Emails that fail for a temporary reason are tried again later, up to five times.

**A log you can trust**

See every email: who sent it, through which connection, what the service answered, and the email itself. Search, filter, export to CSV and send an email again.

Links and codes that give access to an account (password resets, login links, verification codes) are hidden before the email is saved, so an old log can never be used to get into the site. You choose what is kept for transactional and marketing emails (the full email, a summary or nothing) and for how long.

**Bounces and spam reports**

Your email service tells your site what happened after sending: delivered, bounced, marked as spam, or rejected. The plugin adds the webhook at the service for you (Brevo, Postmark, Mailjet, Mailgun and others, when the key allows it) or shows the steps. Signed webhooks are checked (SendGrid, Mailgun, Resend, MailerSend, Amazon SES through SNS).

* The log shows each email as delivered, bounced or marked as spam, with the reason.
* Addresses that do not exist and people who marked an email as spam are blocked by the rules you choose: for example, a bounced address still gets password and order emails but no newsletters. Repeated soft bounces block marketing emails.
* One list for the whole site: Aluleth Forms, Popups and the other Aluleth plugins skip these addresses too, and the contact's history shows the bounce.
* Block an address by hand, allow one again, export the list.

**Deliverability checks**

Will your emails reach the inbox? The Deliverability screen checks your sending domain against the rules of Gmail, Yahoo and Microsoft: SPF (one record, not too many lookups), DKIM for each connection (asked to the service when the key allows it), DMARC, alignment of your domain, spam report and bounce rates, and whether replies can come back. Each problem comes with the DNS record to copy. Send a check email through every connection in one click.

**Import from other SMTP plugins**

Coming from WP Mail SMTP, FluentSMTP or Post SMTP? Import their connections in one click, keys included, then deactivate the old plugin.

**Alerts when sending fails**

Get an email, a Slack, Discord or Telegram message when emails fail, at most once every 15 minutes.

**Works with every plugin**

Each email is recognized by the plugin or theme that sends it (WooCommerce, Aluleth Forms, your contact form…), for the log and the routing rules. Emails of newsletter plugins (MailPoet, Newsletter, Mailster, FluentCRM and others) are marketing emails. Developers can mark their own emails with the header `X-Aluleth-Kind: marketing` or the `aluleth_smtp/kind` filter.

== Installation ==

1. Install and activate the plugin.
2. Go to Aluleth › Email sending and click "Add a connection".
3. Choose your service, paste your key and save: the plugin checks it with the service.
4. Send a test email from the overview.

For big lists, a real server cron keeps the pace of the queue steady. The command is shown in Aluleth › Email sending › Routing and queue.

== Frequently Asked Questions ==

= Do I need an account with an email service? =

Yes. Aluleth SMTP connects your site to the service you choose. Most services have a free plan for small sites.

= Where are my keys stored? =

In your database, encrypted with a key made from your site's secret keys. They are never shown again in the settings. To keep them out of the database, add constants to wp-config.php, for example `define( 'ALULETH_SMTP_SENDGRID_API_KEY', '…' );` (the setting screen shows the name of each constant). If your site's secret keys change, paste the keys again.

= Why does my From address change? =

Email services only accept From addresses of a domain you verified with them. When "Use this From email for every email" is checked, or when a plugin uses WordPress's default address (wordpress@…), the connection's From address is used. The address the plugin asked for stays as Reply-To when needed, so replies still reach the right person.

= Can I use Gmail or Microsoft 365? =

Yes. Choose "Gmail / Google Workspace" or "Microsoft 365 / Outlook", follow the steps to make your own app at Google Cloud or Microsoft Entra (about 5 minutes), paste its client ID and secret, then sign in. Emails go out through the Gmail API or Microsoft Graph, from your mailbox. With Google, publish the app ("In production"): an app left in "Testing" stops working after 7 days. Gmail limits sending to about 500 emails a day (2,000 with Google Workspace).

= What happens if a service is down? =

The fallback connection sends the email right away. Without a fallback, the email waits in the queue and is tried again after 1 minute, 5 minutes, 30 minutes, 2 hours and 6 hours.

= How are bounces and spam reports received? =

Each connection gets a secret address (a webhook) where the email service reports what happened to each email. On the connection's screen, click "Set it up for me" when the service allows it, or follow the steps to add the address in your account at the service. With an SMTP server, nothing is reported.

= Will a bounced address still get password resets and receipts? =

By default, yes: an address that bounced gets no marketing email, but still gets the emails it asks for (a password reset, an order). Change it in Email sending › Bounces.

= Does it work with WP-CLI? =

Yes: `wp aluleth-smtp test you@example.com`, `wp aluleth-smtp status` and `wp aluleth-smtp queue` (for a server cron).

= What happens to my data when I delete the plugin? =

They are kept, so nothing is lost if you install the plugin again. To erase the connections, settings and email log for good, tick them in Aluleth › Settings › Data and privacy before deleting the plugin. Personal data in the log can be exported and erased with the WordPress privacy tools.

== External services ==

This plugin sends your site's emails through the email service you connect, and only to that service. Nothing is sent to us.

When an email is sent, the connected service receives the email: sender, recipients, subject, content and attachments, a reference number of the email in your log, plus your key to identify your account. Like WordPress itself, each request names your site's address in its user agent. When you save a connection, the plugin also asks the service whether the key works and whether the From address is accepted. Nothing is sent before you add a connection.

When you ask for it, the plugin also uses your key to add or remove a webhook at the service (the address of your site where the service reports delivered emails, bounces and spam reports) and, on the Deliverability screen, to ask whether your domain is authenticated. The service then sends to your site the events of your emails: recipient address, event, date and reason.

With Gmail / Google Workspace, the plugin signs in through your own Google Cloud app (`https://accounts.google.com`, `https://oauth2.googleapis.com`) and sends through the Gmail API (`https://gmail.googleapis.com`), only with the "send email" permission and your address: [Google Terms of Service](https://policies.google.com/terms), [Google Privacy Policy](https://policies.google.com/privacy).

With Microsoft 365 / Outlook, the plugin signs in through your own Microsoft Entra app (`https://login.microsoftonline.com`) and sends through Microsoft Graph (`https://graph.microsoft.com`), only with the "send mail" permission and your profile's address: [Microsoft Services Agreement](https://www.microsoft.com/servicesagreement), [Microsoft Privacy Statement](https://privacy.microsoft.com/privacystatement).

With Amazon SES, events come through Amazon SNS: the plugin downloads Amazon's certificate (from `https://sns.REGION.amazonaws.com`) to check each message, and opens the confirmation link Amazon sends when you subscribe the address (only `https://sns.REGION.amazonaws.com` addresses are opened). The Deliverability screen reads public DNS records of your domain with your server's own DNS resolver.

Each service below is only contacted when you added a connection to it, at the addresses listed:

* SendGrid by Twilio (`https://api.sendgrid.com`, or `https://api.eu.sendgrid.com` when you choose the EU region): [Terms of service](https://www.twilio.com/en-us/legal/tos), [Privacy notice](https://www.twilio.com/en-us/legal/privacy)
* Amazon SES (`https://email.REGION.amazonaws.com`, where REGION is the AWS region you choose for the connection, for example `https://email.eu-west-1.amazonaws.com`; regions offered: us-east-1, us-east-2, us-west-1, us-west-2, ca-central-1, sa-east-1, eu-west-1, eu-west-2, eu-west-3, eu-central-1, eu-central-2, eu-south-1, eu-north-1, ap-south-1, ap-northeast-1, ap-northeast-2, ap-northeast-3, ap-southeast-1, ap-southeast-2, ap-southeast-3, af-south-1, me-south-1, il-central-1, us-gov-west-1): [Service terms](https://aws.amazon.com/service-terms/), [Privacy notice](https://aws.amazon.com/privacy/)
* Brevo (`https://api.brevo.com`): [Terms of use](https://www.brevo.com/legal/termsofuse/), [Privacy policy](https://www.brevo.com/legal/privacypolicy/)
* Mailgun (`https://api.mailgun.net`, or `https://api.eu.mailgun.net` when you choose the EU region): [Terms of service](https://www.mailgun.com/legal/terms/), [Privacy policy](https://www.mailgun.com/legal/privacy-policy/)
* Postmark (`https://api.postmarkapp.com`): [Terms of service](https://postmarkapp.com/terms-of-service), [Privacy policy](https://postmarkapp.com/privacy-policy)
* Resend (`https://api.resend.com`): [Terms of service](https://resend.com/legal/terms-of-service), [Privacy policy](https://resend.com/legal/privacy-policy)
* MailerSend (`https://api.mailersend.com`): [Terms of use](https://www.mailersend.com/legal/terms-of-use), [Privacy policy](https://www.mailersend.com/privacy-policy)
* Mailjet (`https://api.mailjet.com`): [Terms](https://www.mailjet.com/legal/terms/), [Privacy policy](https://www.mailjet.com/legal/privacy-policy/)
* SMTP2GO (`https://api.smtp2go.com`): [Terms](https://www.smtp2go.com/terms/), [Privacy policy](https://www.smtp2go.com/privacy/)
* SparkPost by Bird (`https://api.sparkpost.com`, or `https://api.eu.sparkpost.com` when you choose the EU region): [Terms](https://bird.com/legal), [Privacy policy](https://bird.com/legal/privacy)
* Elastic Email (`https://api.elasticemail.com`): [Terms of use](https://elasticemail.com/resources/usage-policies/terms-of-use), [Privacy policy](https://elasticemail.com/legal/privacy-policy)
* Other SMTP: the SMTP server you enter receives the emails, under the terms of its provider.

Failure alerts are optional. When you turn them on, a short message (the site name, the number of failed emails, the subject and error of the last one, and a link to your email log) is sent to the service you choose:

* Slack, through the incoming webhook you paste (only `hooks.slack.com/services/…` addresses are accepted): [User terms of service](https://slack.com/terms-of-service/user), [Privacy policy](https://slack.com/trust/privacy/privacy-policy)
* Discord, through the webhook you paste. Only Discord webhook addresses are accepted: `https://discord.com/api/webhooks/…`, and the other forms Discord gives, `https://discordapp.com/api/webhooks/…`, `https://canary.discord.com/api/webhooks/…` and `https://ptb.discord.com/api/webhooks/…`: [Terms of service](https://discord.com/terms), [Privacy policy](https://discord.com/privacy)
* Telegram (`https://api.telegram.org`), through the bot you create: [Terms of service](https://telegram.org/tos), [Privacy policy](https://telegram.org/privacy)

= Shared Aluleth screens =

Aluleth SMTP comes with the screens shared by all Aluleth plugins (Aluleth › Settings and Aluleth › Connections). They are mostly used by Aluleth Forms and the other Aluleth plugins. None of the services below is contacted unless you paste your own key in one of these screens. With Aluleth SMTP alone, that is when you click a "Save and check" or "Connect" button there, and, once captcha keys are saved, when you open Aluleth › Settings › Security: it shows the service's test check, which loads its script in your browser.

* Aluleth › Connections: when you connect an account, your site sends your key to the service to read your lists, tags and fields. Mailchimp (`*.api.mailchimp.com`): [Terms of use](https://mailchimp.com/legal/terms/), [Privacy policy](https://www.intuit.com/privacy/statement/). Brevo (`api.brevo.com`): [Terms of use](https://www.brevo.com/legal/termsofuse/), [Privacy policy](https://www.brevo.com/legal/privacypolicy/). MailerLite (`connect.mailerlite.com`): [Terms of service](https://www.mailerlite.com/legal/terms-of-service), [Privacy policy](https://www.mailerlite.com/legal/privacy-policy). Kit (`api.kit.com`): [Terms of service](https://kit.com/terms), [Privacy policy](https://kit.com/privacy). Mautic: the address of your own Mautic and its API user, [Mautic privacy policy](https://mautic.org/privacy-policy/). Telegram (`api.telegram.org`), with the token of your bot, to read its name and the chats that wrote to it: [Terms of service](https://telegram.org/tos), [Privacy policy](https://telegram.org/privacy). Slack (`https://hooks.slack.com/services/…`) and Discord (`https://discord.com/api/webhooks/…`, also the `discordapp.com`, `canary.discord.com` and `ptb.discord.com` forms) webhooks and your own webhook addresses only receive a message when another Aluleth plugin sends one (for example a form entry with Aluleth Forms), or a sample message when you click "Send a test".
* Aluleth › Settings › Security: "Save and check the keys" sends your secret key and a test answer to the captcha service you chose, to tell you whether the keys work. Cloudflare Turnstile (`challenges.cloudflare.com`, for the test check and the key check): [Terms of use](https://www.cloudflare.com/website-terms/), [Privacy policy](https://www.cloudflare.com/privacypolicy/). hCaptcha (`js.hcaptcha.com` for the test check, `api.hcaptcha.com`): [Terms of service](https://www.hcaptcha.com/terms), [Privacy policy](https://www.hcaptcha.com/privacy). Google reCAPTCHA (`www.google.com/recaptcha`, for the test check and the key check): [Terms of service](https://policies.google.com/terms), [Privacy policy](https://policies.google.com/privacy). CleanTalk (`moderate.cleantalk.org`), a short test message with your access key: [Terms of service](https://cleantalk.org/publicoffer), [Privacy policy](https://cleantalk.org/privacy). These services check visitors only on the forms of Aluleth Forms; Aluleth SMTP adds no captcha to your pages.
* PayPal: the Payments settings and the PayPal code are only used with Aluleth Forms; they are hidden and never run with Aluleth SMTP alone. [User agreement](https://www.paypal.com/webapps/mpp/ua/useragreement-full), [Privacy statement](https://www.paypal.com/webapps/mpp/ua/privacy-full).

== Changelog ==

= 0.3.6 =
* Plugin header: the author link now points to the WordPress.org profile.

= 0.3.5 =
* The setup notice only shows on the Plugins screen, and can be hidden.
* Security tokens are checked before any value of a form is read.
* Screens escape every value, and the HTML parts they assemble go through WordPress's HTML filter before they are shown.
* Attachments of emails waiting in the queue are kept under neutral file names in the plugin's private uploads folder.
* The readme lists the exact address of each service.

= 0.3.4 =
* Email sending is for site administrators only: whoever controls a connection receives every email of the site, password resets included. The Access setting for it is gone.
* The deliverability check and the secret event address are only made when you click a button or save a connection, never just by opening a screen.
* Signing in with Google or Microsoft also checks a WordPress security token when you come back.
* Slack and Discord alerts accept and post only to Slack and Discord webhook addresses.
* Deleting the plugin with "delete my data" also removes the saved access tokens; deactivating it stops every scheduled send.
* Shared Aluleth screens and their services are described in the readme.

= 0.3.3 =
* The text version of HTML emails no longer contains hidden parts such as the preview text of newsletters, nor invisible spacing characters.

= 0.3.2 =
* Bounces: "What happens after a bounce" uses choices you can read in full instead of drop-down lists that cut the longer answers.
* Drop-down lists show their arrow again.

= 0.3.1 =
* SendGrid: when your plan has no free event webhook left, the message says how many it allows and which addresses use them, and how to free one.
* The test email names the service once when the connection keeps the service's name.
* Form values are cleaned as soon as they are received (API keys and passwords are kept exactly as typed, stored encrypted).

= 0.3.0 =
* New: Gmail / Google Workspace (Gmail API) and Microsoft 365 / Outlook (Microsoft Graph), with your own app: step-by-step guide, the redirect address to copy, sign-in button, renewal of the access, warning when the From address is not the signed-in account. 14 services in all.
* Import: the app keys of WP Mail SMTP and FluentSMTP Gmail or Outlook connections come along (sign in again here).
* Deliverability: a Gmail address sending through Gmail (or Outlook.com through Microsoft) is no longer flagged as a free address.
* The log keeps a bounce's reason on one line.
* Shared Aluleth data (contacts, tags, lists, consents, blocked addresses, queue) now lives in tables with the "aluleth" prefix. They are renamed automatically on update; nothing is lost. Update all your Aluleth plugins at the same time.

= 0.2.0 =
* New: bounces and spam reports. A secret webhook address per connection, set up for you at Brevo, Postmark, Mailjet, Mailgun, SendGrid, Resend, MailerSend, SMTP2GO and SparkPost when the key allows it, or by hand (also Elastic Email and Amazon SES through SNS). Signatures checked for SendGrid, Mailgun, Resend, MailerSend and Amazon SNS.
* New: the log shows delivered, bounced, rejected and spam-reported emails, with each event in "What happened". Rejections reported after sending (for example a From address the service does not accept) trigger the failure alert.
* New: Bounces screen. Blocked addresses (hard bounces, repeated soft bounces, spam reports, unsubscribed, blocked by hand) with the rules you choose, shared with the other Aluleth plugins; allow again, block by hand, export CSV. Events of other sites on the same service account are left alone.
* New: Deliverability screen. SPF, DKIM per connection, DMARC, alignment, spam and bounce rates, MX, with the DNS records to copy and a check email through every connection.
* New: import from WP Mail SMTP, FluentSMTP and Post SMTP, keys included; a warning when another SMTP plugin is still active.

= 0.1.0 =
* First version: 12 email services (SendGrid, Amazon SES, Brevo, Mailgun, Postmark, Resend, MailerSend, Mailjet, SMTP2GO, SparkPost, Elastic Email and any SMTP server), keys checked and stored encrypted, fallback connection, routing rules by plugin, kind of email and From address, background queue with sending limits and retries, email log with hidden reset links and codes, resend, CSV export, test email, overview with daily numbers and senders, failure alerts by email, Slack, Discord and Telegram, WP-CLI commands, privacy export and erase. Brevo connections warn when the From address is not a validated sender.
