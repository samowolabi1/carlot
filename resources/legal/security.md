This page explains how **{company}** ("LotLink") protects the Platform and your data, how to keep yourself safe when buying, selling or borrowing through LotLink, and how to report a security problem. It applies to the whole Platform: the marketplace, car lots' dashboards and mini-sites, the lender portal, the admin panel, our apps and API. It forms part of our [Terms of Use](/terms) and sits alongside our [Privacy Policy](/privacy).

## 1. How we protect the Platform

- **Encryption:** all traffic uses HTTPS (with HSTS). Sensitive data such as car loan applications, lenders' API keys, social media tokens and sign-in secrets are encrypted in our database.
- **Sign-in:** one-time codes by WhatsApp or email that expire in minutes, with limits on how many can be sent and tried; optional passwords stored only as one-way hashes; "keep me signed in" only when you choose it. LotLink staff must use a password and two-factor authentication.
- **Access control:** each car lot and each lender only sees its own records; staff roles limit who can see money, costs and settings; every car loan application goes only to the lender the buyer chose.
- **Private files:** CAC certificates, lender licences and IDs, loan documents, support attachments, trade-in photos and receipts are stored privately and opened only through short-lived signed links.
- **Protection against common attacks:** content security policy, anti-forgery (CSRF) tokens, rate limits on sign-in, messages, searches and forms, strict input validation, signed webhooks from payment providers and lenders, and checks that payments are real before anything is marked paid.
- **Monitoring and records:** audit logs of changes to money, statuses, staff and settings, including when LotLink support uses "log in as" to help you; fraud signals on listings for our team to review.
- **Incidents:** if a breach is likely to put your rights at risk we notify the Nigeria Data Protection Commission within 72 hours and tell affected people as the law requires.

No system is completely secure, and some parts of the Platform depend on others (mobile networks, WhatsApp, email and payment providers). See the exclusions in section 10 of the [Terms of Use](/terms).

## 2. Staying safe: buyers

- **LotLink never asks you to pay for a car, a deposit or a reservation into a LotLink account.** Pay only the car lot, into the account shown on the lot's LotLink page or receipt, and confirm it with the lot in person or on its listed phone number.
- Inspect the car and its papers before paying: VIN/chassis number, customs papers, proof of ownership and registration. Visit the lot or meet in a safe, public place.
- **Never share your sign-in code** with anyone, even someone claiming to be LotLink. We will never ask for it.
- **Car loans:** LotLink does not lend and does not charge for loan applications. Never pay an "approval", "processing" or "insurance" fee to a person or a personal account to get a loan. Pay loan charges only as written in the lender's own loan agreement, to the lender's official account.
- Be wary of prices far below the market, pressure to pay quickly, or requests to move the conversation off LotLink before you have seen the car.

## 3. Staying safe: car lots

- Keep your staff list up to date and remove people who leave. Give owners' and managers' roles only to people who need them.
- Check that the bank account on your LotLink profile is correct; you are told whenever it changes.
- Confirm a buyer's payment in your own bank app before releasing a car. Do not rely on screenshots or SMS alerts alone.
- Hand over papers only when the sale is complete, and record it in Lot Manager.

## 4. Staying safe: lenders

- Keep your portal accounts, API key and webhook secret private, and rotate the secret if you think it has leaked (Lender portal → Settings).
- Do your own identity (BVN/NIN), credit and affordability checks before lending. LotLink does not verify buyers' income or documents.
- Tell buyers how to continue with you only through your official channels, and never ask them for fees outside your loan agreement.

## 5. Reporting a security problem

If you find a vulnerability, please email **{security_email}** with the steps to reproduce it. We ask you to:

- give us reasonable time to fix it before telling anyone else;
- not access, change or delete other people's data (use your own test accounts), not degrade the service (no denial-of-service or spam), and stop and tell us as soon as you reach any personal data;
- not use social engineering, physical attacks, or attacks on our providers.

If you follow these rules and act in good faith, we will not take legal action against you for your research, and we will keep you updated. This does not authorise testing that is otherwise unlawful, including under the Cybercrimes (Prohibition, Prevention, etc.) Act 2015 (as amended).

To report fraud, a suspicious listing, lot or lender, use **Report** on the listing or email {email}. If you have lost money, also report it to your bank and the police.
