<?php
/**
 * The starter messages, as written by the client.
 *
 * Source: "Prompts for the occasion", supplied 17 November 2026. Thirty-four
 * messages in six groups, plus eight written here for leaving and retirement,
 * which that document does not cover — those are marked in place and are for
 * the client to replace. The client's wording is not edited here beyond two
 * mechanical changes:
 *
 *   (Name)              becomes {first name}, which the plugin fills in from
 *                       the recipient when the card is written.
 *
 *   [your company]      becomes {our company}: the sender's trading name
 *                       from Settings, falling back to the registered name.
 *                       Not {company}, which is the recipient's — swapping
 *                       those two would sign a card with the name of the
 *                       person receiving it.
 *
 *   (round brackets)    become [square brackets]. The document uses both for
 *                       the same job; one convention means the writer can
 *                       spot an unfilled placeholder and say so before a pen
 *                       puts it on a card in ink.
 *
 * Everything in [square brackets] is for the person sending to replace. They
 * are deliberately NOT merge tags: {offer code} and {offer expiry} are filled
 * only by a campaign, and an occasion using them would write a blank onto a
 * card. See CardsCo_Merge::fields().
 *
 * Titles are not in the client's document; they are written here so the
 * writer's chips have something short to show.
 *
 * @package CardsCo
 */

defined( 'ABSPATH' ) || exit;

return array(

	/* ------------------------------------------------- Christmas & seasonal */

	array( 'christmas', 'warm', 'Christmas — thank you for the year',
		'As the year winds down, we want to thank you for being part of our journey. We hope your Christmas is filled with rest, joy and time with the people who matter most. Wishing you a wonderful festive season.' ),

	array( 'christmas', 'warm', 'Christmas — festive offer',
		'The festive season is here, and we have something for you. Enjoy [offer] until [date] as our way of making this season a little more special. Visit [your website] to take advantage of it.' ),

	array( 'new-year', 'sincere', 'New year — the year ahead',
		'A new year brings fresh plans, new goals and more opportunities to grow. Thank you for being part of our journey so far. We look forward to working with you again in the year ahead.' ),

	array( 'christmas', 'warm', 'Seasonal — something special',
		'We’re marking the season with something special. Enjoy [offer] on [product or service] until [date]. If you’ve been thinking about giving it a try, this is a good time to get started.' ),

	array( 'christmas', 'thankful', 'Christmas — after a move',
		'Thank you for choosing us to help with your move this year. We hope you\'re enjoying your new home. Happy Christmas.' ),

	array( 'christmas', 'thankful', 'Christmas — property care',
		'Thank you for letting us look after your property this year. Happy Christmas from everyone at {our company}.' ),

	array( 'christmas', 'warm', 'Christmas — short and open',
		'Happy Christmas from all of us at {our company}. Our door is always open.' ),

	/* ------------------------------------------------------------ Thank you */

	array( 'thank-you', 'thankful', 'Thank you — after a purchase',
		'Thank you for choosing us. We appreciate your business and hope you’re happy with your purchase. We look forward to serving you again.' ),

	array( 'thank-you', 'thankful', 'Thank you — asking for feedback',
		'Thank you for your recent purchase. We hope everything met your expectations. Your feedback would mean a lot to our team, and we’d love to hear what you think.' ),

	array( 'thank-you', 'warm', 'Thank you — new subscriber',
		'Thank you for becoming a customer. We’re glad to have you with us and look forward to helping you get the most out of your subscription.' ),

	array( 'thank-you', 'warm', 'Thank you — come back and see us',
		'Thank you for shopping with us. We hope you’re enjoying your purchase. When you’re ready for something new, we’d love to welcome you back. Explore our latest offerings at [your website].' ),

	array( 'thank-you', 'thankful', 'Thank you — with a discount',
		'We appreciate your recent purchase and would love to welcome you back. Here’s a little something for your next order: use [discount code] to enjoy [offer]. We hope to see you again soon.' ),

	/* -------------------------------------------------------- Special offers */

	array( 'promotion', 'thankful', 'Offer — giving something back',
		'We appreciate your continued support and would love to give something back. Enjoy [discount] on your next order with us. Simply use [discount code] at checkout. We hope to see you again soon!' ),

	array( 'promotion', 'warm', 'Offer — seasonal',
		'We\'re celebrating the season with a special offer just for you. Enjoy [discount or offer] on [product or service] until [date]. Visit our website to take advantage of it.' ),

	array( 'promotion', 'sincere', 'Offer — for valued customers',
		'As our valued customer, we\'d like to give you access to something special. Enjoy [exclusive offer] on [product or service]. Thank you for choosing us!' ),

	array( 'promotion', 'playful', 'Offer — refer a friend',
		'Good things are even better when shared! Refer a friend to {our company} and enjoy [reward or offer] when they complete their first purchase. Visit our website to get your referral code.' ),

	array( 'promotion', 'warm', 'Offer — first purchase',
		'Thinking about trying something new? We\'d love to make your first experience with us a little sweeter. Enjoy [discount or offer] on your first purchase. Use code [discount code] at checkout.' ),

	/* ----------------------------------------------------- Customer milestones */

	array( 'customer-anniversary', 'thankful', 'Milestone — one year',
		'It’s been a year since you joined us, and we wanted to mark the occasion. Thank you for choosing us over the past year. We’re glad to have you as part of our journey.' ),

	array( 'customer-anniversary', 'sincere', 'Milestone — years together',
		'Some relationships are worth taking a moment to appreciate. Thank you for being with us for [number of years]. Your continued trust means a great deal to our team.' ),

	array( 'customer-anniversary', 'warm', 'Milestone — how far you’ve come',
		'We’ve enjoyed being part of your journey, and it’s great to see how far you’ve come. Thank you for letting us play a role in your progress. Here’s to everything you’re building next.' ),

	array( 'customer-anniversary', 'warm', 'Milestone — reached with us',
		'You’ve reached [milestone] with us, and we thought it deserved a little recognition. Thank you for making us part of your routine. We hope to help you reach many more milestones.' ),

	array( 'customer-anniversary', 'formal', 'Milestone — partnership',
		'We’ve reached [milestone] in our partnership, and we’re grateful for the trust and collaboration that brought us here. Thank you for building with us. We look forward to what comes next.' ),

	array( 'customer-anniversary', 'thankful', 'Milestone — continued support',
		'Your continued support hasn’t gone unnoticed. Thank you for choosing us time and again. We’re grateful to have customers like you and look forward to giving you more reasons to stay.' ),

	array( 'customer-anniversary', 'warm', 'Milestone — with a gift',
		'We’re celebrating [milestone] with you and wanted to make it a little more special. Please enjoy [gift or offer] as a small thank-you for being part of our journey.' ),

	array( 'customer-anniversary', 'sincere', 'Milestone — the road ahead',
		'It’s been great working with you so far. As we mark [milestone], we’re excited about the opportunities ahead and look forward to continuing to support your goals.' ),

	/* ------------------------------------------------------------ Birthdays */

	array( 'birthday', 'warm', 'Birthday — the year ahead',
		'Happy birthday! We hope the year ahead brings you good health, exciting opportunities and plenty of reasons to celebrate. Enjoy your special day.' ),

	array( 'birthday', 'warm', 'Birthday — from the company',
		'Happy birthday from all of us at {our company}. We\'re glad to have you as part of our community. We hope you have a lovely celebration and a fantastic year ahead.' ),

	array( 'birthday', 'sincere', 'Birthday — a pleasure working with you',
		'Happy birthday! It\'s been a pleasure working with you, and we hope the year ahead brings you happiness, success and exciting new opportunities. Enjoy your special day!' ),

	array( 'birthday', 'warm', 'Birthday — for the team',
		'Happy birthday, {first name}! Thank you for being an important part of our team. We appreciate all you bring to {our company} and hope you have a fantastic day and a wonderful year ahead.' ),

	array( 'birthday', 'thankful', 'Birthday — grateful to work together',
		'Happy birthday! We\'re grateful for the opportunity to work with you and look forward to more great things together. Wishing you happiness, good health and continued success in the year ahead.' ),

	array( 'birthday', 'playful', 'Birthday — with a treat',
		'It\'s your birthday, and we\'d love to celebrate with you! Enjoy [discount or offer] on your next purchase as a little birthday treat from us. Have a wonderful day!' ),

	/* ------------------------------------------------ Leaving & retirement */

	/*
	 * NOT the client's wording. "Prompts for the occasion" has nothing for
	 * leaving or retirement, and the occasion is on the approved list, so
	 * these eight are written here to the same pattern so the group is not
	 * empty on the site. They are meant to be replaced: the wording for
	 * every other group came from the client and this group should too.
	 */

	array( 'leaving', 'warm', 'Leaving — thank you and good luck',
		'Thank you for everything you have brought to {our company}, {first name}. You will be missed here, and we are grateful for the part you played. Wishing you every success in what comes next.' ),

	array( 'leaving', 'sincere', 'Leaving — the mark you leave',
		'Your work here has made a real difference, and it will not be forgotten. Thank you for [contribution]. We hope the next step brings you everything you are hoping for.' ),

	array( 'leaving', 'thankful', 'Leaving — from the team',
		'From all of us at {our company}, thank you. It has been a pleasure working alongside you, and we are sorry to see you go. Please keep in touch.' ),

	array( 'leaving', 'warm', 'Leaving — after a long stay',
		'[Number] years with us, and you leave with our thanks. Thank you for all you have given {our company}, {first name}. Good luck with the new role.' ),

	array( 'retirement', 'warm', 'Retirement — time to enjoy it',
		'Congratulations on your retirement, {first name}. After [number] years of hard work, it is time to enjoy it. Thank you for everything you have given {our company}.' ),

	array( 'retirement', 'sincere', 'Retirement — a career to be proud of',
		'A career to be proud of, and a retirement well earned. Thank you for the years you gave us, and for everything you taught the people around you. Enjoy every bit of it.' ),

	array( 'retirement', 'thankful', 'Retirement — thank you for the years',
		'Thank you for [number] years at {our company}. You have been part of so much of what we have built here. Wishing you a long, happy and very well-deserved retirement.' ),

	array( 'retirement', 'playful', 'Retirement — no more Monday mornings',
		'No more early starts, no more Monday mornings. Congratulations, {first name}. Enjoy the rest, and do not be a stranger.' ),

	/* --------------------------------------------------- Promotions & offers */

	array( 'promotion', 'warm', 'Promotion — claim the offer',
		'We have something special for you. Enjoy [discount or offer] on [product or service] until [date]. Use code [discount code] at checkout to claim the offer.' ),

	array( 'promotion', 'sincere', 'Promotion — upgrade',
		'Ready to get more out of [product or service]? You can now upgrade to [plan or package] and enjoy [specific benefits]. Take a look at the options here: [link].' ),

	array( 'promotion', 'warm', 'Promotion — limited time',
		'For a limited time, you can enjoy [offer] on [product or service]. The offer ends on [date], so visit [link] if you’d like to take advantage of it.' ),

	array( 'promotion', 'thankful', 'Promotion — thank-you reward',
		'We appreciate your continued support and wanted to give you something in return. Enjoy [reward or offer] on your next purchase as a thank-you for choosing us.' ),
);
