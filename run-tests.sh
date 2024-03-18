REPO_BASE=/var/www
CUSTOM=${REPO_BASE}/web/modules/custom
MYSQL_URL=$(cat ${REPO_BASE}/unversioned/dburl)
SQLITE_URL=sqlite://localhost/sites/simpletest/.ht.sqlite

# MySQL
# ebms_article/tests/src/Functional/ArticleTest.php
# ebms_article/tests/src/FunctionalJavascript/FullTextTest.php
# ebms_article/tests/src/FunctionalJavascript/SearchTest.php
# ebms_article/tests/src/Kernel/ArticleTest.php
# ebms_board/tests/src/Kernel/BoardTest.php
# ebms_core/tests/src/Functional/VocabularyTest.php
# ebms_doc/tests/src/FunctionalJavascript/DocTest.php
# ebms_group/tests/src/FunctionalJavascript/GroupTest.php
# ebms_home/tests/src/FunctionalJavascript/HomeTest.php
# ebms_import/tests/src/FunctionalJavascript/ImportTest.php
# ebms_import/tests/src/Kernel/ImportTest.php
# ebms_journal/tests/src/FunctionalJavascript/JournalTest.php
# ebms_user/tests/src/Functional/ProfileTest.php
/usr/bin/sed "s#@@SIMPLETEST_DB@@#${MYSQL_URL}#" < phpunit.xml.template > phpunit.xml
${REPO_BASE}/vendor/bin/phpunit --group mysql web/modules/custom

# SQLite
# ebms_article/tests/src/Kernel/SearchTest.php
# ebms_meeting/tests/src/FunctionalJavascript/MeetingTest.php
# ebms_report/tests/src/FunctionalJavascript/ReportsTest.php
# ebms_review/tests/src/FunctionalJavascript/PacketTest.php
# ebms_review/tests/src/FunctionalJavascript/QueueTest.php
# ebms_summary/tests/src/FunctionalJavascript/SummaryTest.php
# ebms_topic/tests/src/FunctionalJavascript/TopicTest.php
# ebms_topic/tests/src/Kernel/TopicTest.php
# ebms_travel/tests/src/FunctionalJavascript/TravelTest.php
/usr/bin/sed "s#@@SIMPLETEST_DB@@#${SQLITE_URL}#" < phpunit.xml.template > phpunit.xml
${REPO_BASE}/vendor/bin/phpunit --exclude-group mysql web/modules/custom
