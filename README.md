# Internationalisation (i18n) for Bolt #

There is no inbuild internationalisation in Bolt, so this is my attempt. What does this Extension do?

### Change the `locale` variable in general configuration ###
We’re not really changing it, but we look in session / cookie and query or domain if an different localisation is requested and reboot the Application localisation (and the Controllers, because they might have dependencies).

### Add a language switch menu ###
Yes, it’s essential - we want to make sure the user can switch the language. So I included a language menu, which can be styled on your templates need, by including your own `_sub_langmenu.twig` in your template.

### Add international content fields ###
I iterate over the `contenttypes.yml` on Application bootstrap and add for each field that has the option `i18n: true` an other field for each enabled localisation (in the Extension’s `config.yml`) with a localisation identifer.

This will require a database check/update (in admin configuration menu) afterwards - so you should do this.

### Twig functions and filters and other helpers ###
To handle multiligual content, I added some neat helpers.

First of all you should look in the included assets `_sub_menu.twig`, which includes some adjustments to handle multilingual menus. Menu changes are very basic: I just made it possible to have `label['_ID']` and `title['_ID']` options which will be used as soon as the global `locale` value (just the first two letter) matches the `_ID` value. If you use routes or paths to generate title and label of your menu items, the `i18_menu()` Twig function will be your friend. This works same as Bolt’s `menu()` function, but additionally takes care of localized title and subtitle.

To output the language switch menu use `i18n_langmenu()` Twig function in your template.

If you want to display a language name, `i18n_lang` filter and function will be helpful - just pass any locale identifer or leave blank to get the current language name.

But your best friends will be `i18n_attribute` Twig function and filter. They implement the basic `attribute()` functionality for Bolt Content, but will take care of localized values. By using this, you will prevent many if-clauses in your template. **You still need to exclude localized fields** in your template files, **if you iterate over all possible fields**.

###### This is no full solution, but will cover and solve 95% of the common problems that appear when you want to localize you Bolt installation.

### Known issues
  - There are still issues with forms. Sometimes it helped for me to just add the missing strings to the corresponding `messages.yml`. They show up as unused, by get used in correct place.
  - You need to take care of dates on your own by using `localdate('%c')` and `localdate('%x')`.
  - Taxonomy is not localized. I still have now idea how to do it in an efficient way. May be you just want to translate the values in frontend by using `{{ __(value) }}`.
  - To switch language in admin interface, you have to take a look at `Extra` menu item. There is currently no way to add menu items in a different location.
