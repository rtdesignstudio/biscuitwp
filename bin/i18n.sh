#!/usr/bin/env bash
# Admin translations: regenerate the .pot, merge it into each .po, compile the .mo.
#   languages/biscuitwp.pot          <- wp i18n make-pot (Romanian source texts)
#   languages/biscuitwp-<locale>.po  <- msgmerge --update (created if missing)
#   languages/biscuitwp-<locale>.mo  <- msgfmt --check
# Prints, per locale, how many texts are translated, fuzzy and untranslated.
# tests/test-i18n-files.php fails when the .pot is stale, a .po is incomplete
# or fuzzy, or a .mo is older than its .po. Needs WP-CLI and gettext.
set -euo pipefail
cd "$(dirname "$0")/.."
export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"

DOMAIN=biscuitwp
POT="languages/${DOMAIN}.pot"
LOCALES="en_US ru_RU"

for tool in wp msgmerge msgfmt; do
	command -v "$tool" >/dev/null || { echo "Missing $tool (WP-CLI / gettext)." >&2; exit 1; }
done

wp i18n make-pot . "$POT" --domain="$DOMAIN" \
	--exclude=tests,node_modules,dist,docs,proxy,standalone,.superpowers

plural_forms() {
	case "$1" in
		ru_*) echo 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);' ;;
		*) echo 'nplurals=2; plural=(n != 1);' ;;
	esac
}

for loc in $LOCALES; do
	po="languages/${DOMAIN}-${loc}.po"
	mo="languages/${DOMAIN}-${loc}.mo"
	if [ ! -f "$po" ]; then
		# Header only; msgmerge below adds every text, untranslated.
		cat > "$po" <<EOF
msgid ""
msgstr ""
"Project-Id-Version: BiscuitWP\n"
"PO-Revision-Date: $(date -u '+%Y-%m-%d %H:%M+0000')\n"
"Last-Translator: BiscuitWP\n"
"Language-Team: ${loc}\n"
"Language: ${loc}\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
"Plural-Forms: $(plural_forms "$loc")\n"
"X-Domain: ${DOMAIN}\n"
EOF
	fi
	msgmerge --quiet --update --backup=none "$po" "$POT"
	msgfmt --check -o "$mo" "$po"
	stats=$(msgfmt --statistics -o /dev/null "$po" 2>&1)
	echo "${loc}: ${stats}"
done
