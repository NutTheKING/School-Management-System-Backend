import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:image_picker/image_picker.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import 'download_helper.dart';
import 'login_page.dart';
import 'mobile_api.dart';

void main() => runApp(const StudentApp());

enum StudentTheme {
  roseGold,
  brown,
  angkorWat,
  bayon,
  waterFestival,
  khmerNewYear,
  bonOmTouk
}

class StudentThemeStyle {
  final String name;
  final String subtitle;
  final Color primary;
  final Color secondary;
  final Color background;
  final Color card;
  final Color accent;
  final IconData icon;
  const StudentThemeStyle(
      {required this.name,
      required this.subtitle,
      required this.primary,
      required this.secondary,
      required this.background,
      required this.card,
      required this.accent,
      required this.icon});
}

const studentThemeStyles = <StudentTheme, StudentThemeStyle>{
  StudentTheme.roseGold: StudentThemeStyle(
      name: 'Rose Gold',
      subtitle: 'Soft rose and warm metallic tones',
      primary: Color(0xff8d4f59),
      secondary: Color(0xffc98f91),
      background: Color(0xff35242b),
      card: Color(0xff633d49),
      accent: Color(0xffffd0ba),
      icon: Icons.local_florist_rounded),
  StudentTheme.brown: StudentThemeStyle(
      name: 'Brown',
      subtitle: 'Classic cocoa and golden sand',
      primary: Color(0xff5b351f),
      secondary: Color(0xff8b5a35),
      background: Color(0xff30231d),
      card: Color(0xff593d2f),
      accent: Color(0xffffd083),
      icon: Icons.coffee_rounded),
  StudentTheme.angkorWat: StudentThemeStyle(
      name: 'Angkor Wat',
      subtitle: 'Sunrise stone and temple gold',
      primary: Color(0xff74351f),
      secondary: Color(0xffad6a36),
      background: Color(0xff2c211b),
      card: Color(0xff5b3828),
      accent: Color(0xffffc65b),
      icon: Icons.temple_buddhist_rounded),
  StudentTheme.bayon: StudentThemeStyle(
      name: 'Bayon',
      subtitle: 'Ancient stone and forest green',
      primary: Color(0xff354b42),
      secondary: Color(0xff687466),
      background: Color(0xff202b29),
      card: Color(0xff354943),
      accent: Color(0xffd9c79a),
      icon: Icons.account_balance_rounded),
  StudentTheme.waterFestival: StudentThemeStyle(
      name: 'Water Festival',
      subtitle: 'River blue and moonlit silver',
      primary: Color(0xff075b78),
      secondary: Color(0xff148bad),
      background: Color(0xff092d43),
      card: Color(0xff124f68),
      accent: Color(0xff8fe9f4),
      icon: Icons.water_rounded),
  StudentTheme.khmerNewYear: StudentThemeStyle(
      name: 'Khmer New Year',
      subtitle: 'Jasmine, celebration and prosperity',
      primary: Color(0xffa52c32),
      secondary: Color(0xffdf6b34),
      background: Color(0xff4b1f26),
      card: Color(0xff7d3033),
      accent: Color(0xffffd65a),
      icon: Icons.celebration_rounded),
  StudentTheme.bonOmTouk: StudentThemeStyle(
      name: 'Bon Om Touk',
      subtitle: 'Royal regatta blue and festival gold',
      primary: Color(0xff163f74),
      secondary: Color(0xff28689a),
      background: Color(0xff0b2545),
      card: Color(0xff173f69),
      accent: Color(0xffffc857),
      icon: Icons.sailing_rounded),
};

class StudentApp extends StatefulWidget {
  const StudentApp({super.key});

  @override
  State<StudentApp> createState() => _StudentAppState();
}

class _StudentAppState extends State<StudentApp> with WidgetsBindingObserver {
  static const String _themePreferenceKey = 'student_app.selected_theme';
  String language = 'en';
  StudentTheme selectedTheme = StudentTheme.angkorWat;
  final MobileApi _api = MobileApi();
  bool _loading = true;
  String? _startupError;
  List<StudentAccount> _students = const [];
  Map<String, dynamic>? _dashboard;
  Timer? _liveSyncTimer;
  bool _syncing = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _restoreSession();
  }

  @override
  void dispose() {
    _liveSyncTimer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _refreshDashboard();
  }

  void _startLiveSync() {
    _liveSyncTimer?.cancel();
    _liveSyncTimer =
        Timer.periodic(const Duration(seconds: 5), (_) => _refreshDashboard());
  }

  Future<void> _refreshDashboard() async {
    if (_syncing || _students.isEmpty) return;
    _syncing = true;
    try {
      await _applyDashboard(await _api.dashboard());
    } catch (_) {
      // Keep the last successful data visible during a temporary connection loss.
    } finally {
      _syncing = false;
    }
  }

  StudentAccount _accountFrom(Map<String, dynamic> data) {
    final name = (data['name_english'] ?? data['name'] ?? 'Student').toString();
    final words =
        name.trim().split(RegExp(r'\s+')).where((word) => word.isNotEmpty);
    final initials = words.take(2).map((word) => word[0].toUpperCase()).join();
    return StudentAccount(name, data['id']?.toString() ?? '',
        data['class']?.toString() ?? '', initials.isEmpty ? 'ST' : initials);
  }

  Future<void> _restoreSession() async {
    try {
      final preferences = await SharedPreferences.getInstance();
      final savedTheme = preferences.getString(_themePreferenceKey);
      if (savedTheme != null) {
        for (final theme in StudentTheme.values) {
          if (theme.name == savedTheme) {
            selectedTheme = theme;
            break;
          }
        }
      }
      final token = await _api.savedToken();
      if (token != null) {
        await _applyDashboard(await _api.dashboard(token));
        _startLiveSync();
      }
    } catch (error) {
      await _api.logout();
      _startupError = error.toString();
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _applyDashboard(Map<String, dynamic> dashboard) async {
    final student = dashboard['student'];
    if (student is! Map)
      throw const MobileApiException('Student profile was not returned');
    if (mounted)
      setState(() {
        _students = [_accountFrom(Map<String, dynamic>.from(student))];
        _dashboard = dashboard;
        _startupError = null;
      });
  }

  Future<void> _login(String studentId, String password) async {
    await _applyDashboard(await _api.login(studentId, password));
    _startLiveSync();
  }

  Future<void> _changeTheme(StudentTheme value) async {
    if (mounted) setState(() => selectedTheme = value);
    final preferences = await SharedPreferences.getInstance();
    await preferences.setString(_themePreferenceKey, value.name);
  }

  Future<void> _logout() async {
    _liveSyncTimer?.cancel();
    await _api.logout();
    if (mounted)
      setState(() {
        _students = const [];
        _dashboard = null;
      });
  }

  @override
  Widget build(BuildContext context) {
    final appStyle = studentThemeStyles[selectedTheme]!;
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'Grandview School',
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(seedColor: appStyle.primary),
        fontFamily: language == 'km' ? 'KhmerOS' : 'sans-serif',
        scaffoldBackgroundColor: const Color(0xfff4f7f5),
      ),
      home: _loading
          ? const Scaffold(body: Center(child: CircularProgressIndicator()))
          : _students.isEmpty
              ? StudentLoginPage(onLogin: _login, initialError: _startupError)
              : BackendDataScope(
                  data: _dashboard ?? const {},
                  child: StudentHomePage(
                    language: language,
                    onLanguageChanged: (value) =>
                        setState(() => language = value),
                    selectedTheme: selectedTheme,
                    onThemeChanged: _changeTheme,
                    studentAccounts: _students,
                    onLogout: _logout,
                  ),
                ),
    );
  }
}

class BackendDataScope extends InheritedWidget {
  final Map<String, dynamic> data;
  const BackendDataScope({super.key, required this.data, required super.child});
  static Map<String, dynamic> of(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<BackendDataScope>()?.data ??
      const {};
  static Map<String, dynamic> student(BuildContext context) =>
      Map<String, dynamic>.from(of(context)['student'] as Map? ?? const {});
  static List<Map<String, dynamic>> list(BuildContext context, String key) =>
      (of(context)[key] as List? ?? const [])
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList();
  static List<Map<String, dynamic>> records(
      BuildContext context, String workflow) {
    final groups = of(context)['records'] as Map? ?? const {};
    return (groups[workflow] as List? ?? const [])
        .whereType<Map>()
        .map((item) => Map<String, dynamic>.from(item))
        .toList();
  }

  @override
  bool updateShouldNotify(BackendDataScope oldWidget) => oldWidget.data != data;
}

String backendAcademicYear(BuildContext context) {
  const workflows = [
    'setting-school',
    'academic-class-schedule',
    'academic-monthly',
    'academic-quarterly',
    'academic-semester'
  ];
  for (final workflow in workflows) {
    for (final row in BackendDataScope.records(context, workflow)) {
      final value =
          '${row['Academic Year'] ?? row['academic_year'] ?? ''}'.trim();
      if (value.isNotEmpty) return value;
    }
  }
  final now = DateTime.now();
  final start = now.month >= 6 ? now.year : now.year - 1;
  return '$start-${start + 1}';
}

Widget backendStudentPhoto(BuildContext context, {double size = 90}) {
  final photo = '${BackendDataScope.student(context)['photo'] ?? ''}'.trim();
  Widget fallback() => Image.asset('assets/student-avatar.png',
      width: size, height: size, fit: BoxFit.cover);
  if (photo.startsWith('data:image') && photo.contains(',')) {
    try {
      return Image.memory(base64Decode(photo.split(',').last),
          width: size,
          height: size,
          fit: BoxFit.cover,
          errorBuilder: (_, __, ___) => fallback());
    } catch (_) {
      return fallback();
    }
  }
  if (photo.startsWith('http://') || photo.startsWith('https://')) {
    return Image.network(photo,
        width: size,
        height: size,
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) => fallback());
  }
  return fallback();
}

Widget backendSchoolLogo({double size = 45}) {
  final url = MobileApi.baseUrl
      .replaceFirst(RegExp(r'api\.php.*$'), 'assets/sr-logo.png');
  return Image.network(url,
      width: size,
      height: size,
      fit: BoxFit.contain,
      errorBuilder: (_, __, ___) => Image.asset('assets/grandview-logo.jpg',
          width: size, height: size, fit: BoxFit.contain));
}

Widget backendDataImage(String value,
    {double? width,
    double? height,
    BoxFit fit = BoxFit.contain,
    Widget? fallback}) {
  final image = value.trim();
  Widget missing() => fallback ?? const SizedBox.shrink();
  if (image.startsWith('data:image') && image.contains(',')) {
    try {
      return Image.memory(base64Decode(image.split(',').last),
          width: width,
          height: height,
          fit: fit,
          errorBuilder: (_, __, ___) => missing());
    } catch (_) {
      return missing();
    }
  }
  if (image.startsWith('http://') || image.startsWith('https://')) {
    return Image.network(image,
        width: width,
        height: height,
        fit: fit,
        errorBuilder: (_, __, ___) => missing());
  }
  return missing();
}

Map<String, dynamic> backendFirstRecord(BuildContext context, String workflow) {
  final rows = BackendDataScope.records(context, workflow);
  return rows.isEmpty ? const {} : rows.first;
}

class StudentHomePage extends StatefulWidget {
  final String language;
  final ValueChanged<String> onLanguageChanged;
  final StudentTheme selectedTheme;
  final ValueChanged<StudentTheme> onThemeChanged;
  final List<StudentAccount> studentAccounts;
  final Future<void> Function() onLogout;
  const StudentHomePage({
    super.key,
    required this.language,
    required this.onLanguageChanged,
    required this.selectedTheme,
    required this.onThemeChanged,
    required this.studentAccounts,
    required this.onLogout,
  });

  @override
  State<StudentHomePage> createState() => _StudentHomePageState();
}

class _StudentHomePageState extends State<StudentHomePage> {
  static const chocolate = Color(0xff6b3f1f);
  static const translations = <String, Map<String, String>>{
    'en': {
      'setting': 'Setting',
      'studentAccount': 'Student account',
      'parentAccount': 'Parent Account',
      'parentAccess': 'Parent access',
      'settings': 'Settings',
      'account': 'Account',
      'privacy': 'Privacy & security',
      'notifications': 'Notifications',
      'language': 'Language',
      'download': 'Download App',
      'logout': 'Log out',
      'home': 'Home',
      'alerts': 'Alerts',
      'messages': 'Messages',
      'welcome': 'Welcome to',
      'dashboard': 'Here is your dashboard',
      'tools': 'STUDENT TOOLS',
      'need': 'What do you need?',
      'viewAll': 'View all'
    },
    'km': {
      'setting': 'ការកំណត់',
      'studentAccount': 'គណនីសិស្ស',
      'parentAccount': 'គណនីអាណាព្យាបាល',
      'parentAccess': 'សិទ្ធិអាណាព្យាបាល',
      'settings': 'ការកំណត់',
      'account': 'គណនី',
      'privacy': 'ឯកជនភាព និងសុវត្ថិភាព',
      'notifications': 'ការជូនដំណឹង',
      'language': 'ភាសា',
      'download': 'ទាញយកកម្មវិធី',
      'logout': 'ចាកចេញ',
      'home': 'ទំព័រដើម',
      'alerts': 'ដំណឹង',
      'messages': 'សារ',
      'welcome': 'សូមស្វាគមន៍',
      'dashboard': 'នេះជាផ្ទាំងព័ត៌មានរបស់អ្នក',
      'tools': 'ឧបករណ៍សិស្ស',
      'need': 'តើអ្នកត្រូវការអ្វី?',
      'viewAll': 'មើលទាំងអស់'
    },
    'zh': {
      'setting': '设置',
      'studentAccount': '学生账户',
      'parentAccount': '家长账户',
      'parentAccess': '家长访问',
      'settings': '设置',
      'account': '账户',
      'privacy': '隐私与安全',
      'notifications': '通知',
      'language': '语言',
      'download': '下载应用',
      'logout': '退出登录',
      'home': '首页',
      'alerts': '通知',
      'messages': '消息',
      'welcome': '欢迎来到',
      'dashboard': '这是您的控制面板',
      'tools': '学生工具',
      'need': '您需要什么？',
      'viewAll': '查看全部'
    },
  };
  String t(String key) =>
      translations[widget.language]?[key] ?? translations['en']![key] ?? key;
  String get languageName =>
      {'en': 'English', 'km': 'ខ្មែរ', 'zh': '中文'}[widget.language]!;

  final GlobalKey<NavigatorState> _contentNavigatorKey =
      GlobalKey<NavigatorState>();

  Future<T?> _pushContent<T>(Widget page) =>
      _contentNavigatorKey.currentState!.push<T>(
        MaterialPageRoute<T>(builder: (_) => page),
      );

  Future<void> _chooseLanguage() async {
    final selected = await _pushContent<String>(
      LanguagePage(selectedLanguage: widget.language),
    );
    if (selected != null) widget.onLanguageChanged(selected);
  }

  StudentThemeStyle get appStyle => studentThemeStyles[widget.selectedTheme]!;
  String get themeName => appStyle.name;

  Future<void> _chooseTheme() async {
    final selected = await _pushContent<StudentTheme>(
      ThemeSelectionPage(selectedTheme: widget.selectedTheme),
    );
    if (selected != null) widget.onThemeChanged(selected);
  }

  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  final PageController _pageController = PageController();
  Timer? _carouselTimer;
  int _page = 0;
  int _navIndex = 0;
  bool _settingsExpanded = true;
  int _selectedStudentIndex = 0;
  Set<String> _readPaymentIds = <String>{};
  Set<String> _readAnnouncementIds = <String>{};
  Set<String> _knownNotificationIds = <String>{};
  bool _notificationSnapshotReady = false;

  List<StudentAccount> get studentAccounts => widget.studentAccounts;

  StudentAccount get activeStudent => studentAccounts[_selectedStudentIndex];

  final tools = const <StudentTool>[
    StudentTool(
      'My Wallet',
      Icons.account_balance_wallet_outlined,
      Color(0xffe8f8f4),
      Color(0xff2dbf99),
      0,
    ),
    StudentTool(
      'My Profile',
      Icons.badge_outlined,
      Color(0xfffff0e8),
      Color(0xffef9b55),
      1,
    ),
    StudentTool(
      'Homework',
      Icons.assignment_outlined,
      Color(0xfffff8e9),
      Color(0xffeab84b),
      2,
    ),
    StudentTool(
      'Attendance',
      Icons.calendar_month_outlined,
      Color(0xffeaf8ed),
      Color(0xff48b96a),
      3,
    ),
    StudentTool(
      'My Grades',
      Icons.workspace_premium_outlined,
      Color(0xffeaf7fb),
      Color(0xff4caacb),
      4,
    ),
    StudentTool(
      'Timetable',
      Icons.access_alarm_rounded,
      Color(0xfff2edfc),
      Color(0xff8a78d3),
      5,
    ),
    StudentTool(
      'Progress',
      Icons.bar_chart_rounded,
      Color(0xfffff7d9),
      Color(0xffe5a82f),
      6,
    ),
    StudentTool(
      'Gallery',
      Icons.photo_library_outlined,
      Color(0xffe7f8f6),
      Color(0xff3ab5aa),
      7,
    ),
    StudentTool(
      'My Classes',
      Icons.co_present_outlined,
      Color(0xfffff0f1),
      Color(0xffef7180),
      8,
    ),
  ];

  @override
  void initState() {
    super.initState();
    _loadReadPayments();
    _loadReadAnnouncements();
    _carouselTimer = Timer.periodic(const Duration(seconds: 5), (_) {
      if (!_pageController.hasClients) return;
      _pageController.animateToPage(
        (_page + 1) % 3,
        duration: const Duration(milliseconds: 450),
        curve: Curves.easeOutCubic,
      );
    });
  }

  String get _paymentReadKey =>
      'mobile.payment.read.${activeStudent.studentId}';

  Future<void> _loadReadPayments() async {
    final preferences = await SharedPreferences.getInstance();
    final saved =
        preferences.getStringList(_paymentReadKey) ?? const <String>[];
    if (mounted) setState(() => _readPaymentIds = saved.toSet());
  }

  Set<String> _currentPaymentIds(BuildContext context) {
    final ids = <String>{};
    for (final row in BackendDataScope.list(context, 'payments')) {
      final id = '${row['receipt'] ?? ''}'.trim();
      if (id.isNotEmpty) ids.add('payment:$id');
    }
    for (final row in BackendDataScope.records(context, 'pre-invoices')) {
      final id = '${row['invoice_no'] ?? row['id'] ?? ''}'.trim();
      if (id.isNotEmpty) ids.add('invoice:$id');
    }
    return ids;
  }

  Future<void> _markPaymentsRead(BuildContext context) async {
    final current = _currentPaymentIds(context);
    if (!mounted) return;
    setState(() => _readPaymentIds = {..._readPaymentIds, ...current});
    final preferences = await SharedPreferences.getInstance();
    await preferences.setStringList(
        _paymentReadKey, _readPaymentIds.toList()..sort());
  }

  String get _announcementReadKey =>
      'mobile.notification.read.${activeStudent.studentId}';

  Set<String> _currentAnnouncementIds(BuildContext context) {
    final ids = <String>{};
    for (final row
        in BackendDataScope.records(context, 'setting-announcement')) {
      ids.add(
          'announcement:${row['id'] ?? ''}:${row['UpdatedAt'] ?? row['updated_at'] ?? ''}');
    }
    for (final row in BackendDataScope.records(context, 'setting-activities')) {
      ids.add(
          'activity:${row['id'] ?? ''}:${row['UpdatedAt'] ?? row['updated_at'] ?? ''}');
    }
    for (final row in BackendDataScope.records(context, 'pre-invoices')) {
      ids.add(
          'invoice:${row['id'] ?? row['invoice_no'] ?? ''}:${row['released_at'] ?? row['created_at'] ?? ''}');
    }
    return ids.where((id) => !id.contains('::')).toSet();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final current = _currentAnnouncementIds(context);
    if (!_notificationSnapshotReady) {
      _knownNotificationIds = current;
      _notificationSnapshotReady = true;
      return;
    }
    final newlyReleased = current.difference(_knownNotificationIds);
    _knownNotificationIds = current;
    if (newlyReleased.isEmpty) return;
    SystemSound.play(SystemSoundType.alert);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(
          content: Text(newlyReleased.length == 1
              ? 'You have a new school notification.'
              : 'You have ${newlyReleased.length} new school notifications.'),
          action: SnackBarAction(
            label: 'VIEW',
            onPressed: () {
              _markAnnouncementsRead(context);
              _pushContent<void>(const SchoolNewsPage());
            },
          ),
        ));
    });
  }

  Future<void> _loadReadAnnouncements() async {
    final preferences = await SharedPreferences.getInstance();
    final saved =
        preferences.getStringList(_announcementReadKey) ?? const <String>[];
    if (mounted) setState(() => _readAnnouncementIds = saved.toSet());
  }

  Future<void> _markAnnouncementsRead(BuildContext context) async {
    final current = _currentAnnouncementIds(context);
    if (!mounted) return;
    setState(
        () => _readAnnouncementIds = {..._readAnnouncementIds, ...current});
    final preferences = await SharedPreferences.getInstance();
    await preferences.setStringList(
        _announcementReadKey, _readAnnouncementIds.toList()..sort());
  }

  @override
  void dispose() {
    _carouselTimer?.cancel();
    _pageController.dispose();
    super.dispose();
  }

  void _showMessage(String message) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(message),
          duration: const Duration(milliseconds: 1400),
          behavior: SnackBarBehavior.floating,
          shape: const StadiumBorder(),
          backgroundColor: const Color(0xff4a2a16),
        ),
      );
  }

  void _openProfile() {
    _pushContent<void>(StudentProfilePage(student: activeStudent));
  }

  Future<void> _chooseStudentAccount() async {
    Navigator.pop(context);
    final selected = await showModalBottomSheet<int>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (sheetContext) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 0, 18, 22),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Switch student account',
                style: TextStyle(fontSize: 19, fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 4),
              Text(
                '${studentAccounts.length} children linked to this parent account',
                style: TextStyle(
                  fontSize: 11,
                  color: Theme.of(sheetContext).colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 14),
              ...List.generate(studentAccounts.length, (index) {
                final student = studentAccounts[index];
                final selected = index == _selectedStudentIndex;
                return Padding(
                  padding: const EdgeInsets.only(bottom: 9),
                  child: Material(
                    color: selected
                        ? Theme.of(sheetContext).colorScheme.primaryContainer
                        : Theme.of(sheetContext).colorScheme.surface,
                    shape: RoundedRectangleBorder(
                      side: BorderSide(
                        color: selected
                            ? chocolate
                            : Theme.of(sheetContext).colorScheme.outlineVariant,
                        width: selected ? 2 : 1,
                      ),
                      borderRadius: BorderRadius.circular(13),
                    ),
                    child: ListTile(
                      onTap: () => Navigator.pop(sheetContext, index),
                      leading: CircleAvatar(
                        backgroundColor: const Color(0xff6b3f1f),
                        foregroundColor: Colors.white,
                        child: Text(student.initials),
                      ),
                      title: Text(
                        student.name,
                        style: const TextStyle(fontWeight: FontWeight.w800),
                      ),
                      subtitle: Text('${student.studentId} • ${student.grade}'),
                      trailing: selected
                          ? const Icon(Icons.check_circle_rounded,
                              color: chocolate)
                          : const Icon(Icons.chevron_right_rounded),
                    ),
                  ),
                );
              }),
            ],
          ),
        ),
      ),
    );
    if (selected != null && selected != _selectedStudentIndex) {
      setState(() => _selectedStudentIndex = selected);
      _contentNavigatorKey.currentState?.popUntil((route) => route.isFirst);
      _showMessage('Switched to ${activeStudent.name}');
    }
  }

  void _openTool(String label) {
    final Widget? page = switch (label) {
      'My Wallet' => const SchoolFeesPage(),
      'My Profile' => StudentProfilePage(student: activeStudent),
      'Homework' => const HomeworkPage(),
      'Attendance' => const AttendancePage(),
      'My Grades' => const BackendRecordsPage(title: 'My Grades', workflows: [
          'academic-master-input',
          'academic-monthly',
          'academic-quarterly',
          'academic-semester',
          'academic-khmer-monthly',
          'academic-khmer-semester'
        ]),
      'Timetable' =>
        const BackendRecordsPage(title: 'Class Timetable', workflows: [
          'academic-class-schedule',
          'academic-teaching-schedule',
          'academic-khmer-schedule'
        ]),
      'Progress' =>
        const BackendRecordsPage(title: 'Academic Progress', workflows: [
          'academic-master-input',
          'academic-monthly',
          'academic-quarterly',
          'academic-semester',
          'academic-khmer-monthly',
          'academic-khmer-semester'
        ]),
      'My Classes' => const ClassesPage(),
      'Gallery' => const SchoolNewsPage(),
      _ => null,
    };
    if (page != null) {
      _pushContent<void>(page);
    } else {
      _showMessage('Opening $label…');
    }
  }

  @override
  Widget build(BuildContext context) {
    return _StudentScope(
      student: activeStudent,
      child: Scaffold(
        key: _scaffoldKey,
        extendBody: true,
        drawerScrimColor: Colors.black54,
        drawer: _buildMenu(),
        body: Navigator(
          key: _contentNavigatorKey,
          onGenerateRoute: (_) => MaterialPageRoute<void>(
            builder: (_) => Container(
              decoration: BoxDecoration(
                color: appStyle.background,
                image: DecorationImage(
                  image: AssetImage('assets/education-tools-background.png'),
                  fit: BoxFit.cover,
                  alignment: Alignment.center,
                  colorFilter: ColorFilter.mode(
                    appStyle.background.withValues(alpha: .32),
                    BlendMode.darken,
                  ),
                ),
              ),
              child: SafeArea(
                top: false,
                child: CustomScrollView(
                  slivers: [
                    SliverToBoxAdapter(child: _buildHero()),
                    SliverToBoxAdapter(child: _buildCarousel()),
                    SliverToBoxAdapter(child: _buildTools()),
                    const SliverToBoxAdapter(child: SizedBox(height: 92)),
                  ],
                ),
              ),
            ),
          ),
        ),
        bottomNavigationBar: _buildNavigation(),
      ),
    );
  }

  Widget _buildHero() {
    return Container(
      height: 184,
      padding: EdgeInsets.fromLTRB(
        18,
        MediaQuery.paddingOf(context).top + 10,
        18,
        16,
      ),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [appStyle.background, appStyle.primary, appStyle.secondary],
        ),
      ),
      child: Stack(
        children: [
          Positioned(
            right: -25,
            bottom: -50,
            child: Icon(
              Icons.school_rounded,
              size: 190,
              color: Colors.white.withValues(alpha: .08),
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  IconButton(
                    onPressed: () => _scaffoldKey.currentState?.openDrawer(),
                    icon: const Icon(Icons.menu_rounded, color: Colors.white),
                  ),
                  const Spacer(),
                  Container(
                    width: 34,
                    height: 34,
                    decoration: BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                      border: Border.all(
                        color: appStyle.accent,
                        width: 3,
                      ),
                    ),
                    clipBehavior: Clip.antiAlias,
                    child: Image.asset(
                      'assets/grandview-logo.jpg',
                      fit: BoxFit.contain,
                    ),
                  ),
                  const SizedBox(width: 7),
                  const Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'GRANDVIEW SCHOOL',
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: 10,
                          fontWeight: FontWeight.w900,
                          letterSpacing: .5,
                        ),
                      ),
                      Text(
                        "View Your Child's Genius",
                        style: TextStyle(color: Colors.white70, fontSize: 8),
                      ),
                    ],
                  ),
                  const Spacer(),
                  InkWell(
                    onTap: _openProfile,
                    child: Container(
                      width: 40,
                      height: 40,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: const Color(0xffffbd70),
                        shape: BoxShape.circle,
                        border: Border.all(color: Colors.white, width: 2),
                      ),
                      clipBehavior: Clip.antiAlias,
                      child: Image.asset(
                        'assets/student-avatar.png',
                        fit: BoxFit.cover,
                      ),
                    ),
                  ),
                ],
              ),
              const Spacer(),
              Text(
                t('welcome'),
                style: TextStyle(color: Colors.white, fontSize: 14),
              ),
              Text(
                activeStudent.name,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 25,
                  height: 1.1,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                t('dashboard'),
                style: TextStyle(color: Colors.white70, fontSize: 11),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildMenu() {
    return Drawer(
      width: MediaQuery.sizeOf(context).width * .82,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.horizontal(right: Radius.circular(2)),
      ),
      backgroundColor: Theme.of(context).colorScheme.surface,
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 8, 14, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  IconButton(
                    visualDensity: VisualDensity.compact,
                    onPressed: () => Navigator.pop(context),
                    icon: const Icon(Icons.close_rounded, size: 19),
                  ),
                  Text(
                    t('setting'),
                    style: TextStyle(
                      fontSize: 13,
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              const Center(child: _SchoolSeal()),
              const SizedBox(height: 12),
              const Text(
                'GRANDVIEW SCHOOL',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 28),
              _ProfileTile(
                initials: activeStudent.initials,
                name: activeStudent.name,
                subtitle: '${activeStudent.grade} • ${activeStudent.studentId}',
                color: const Color(0xff50b9af),
                imageAsset: 'assets/student-avatar.png',
                onTap: () {
                  Navigator.pop(context);
                  _openProfile();
                },
              ),
              const SizedBox(height: 8),
              _ProfileTile(
                initials: '${studentAccounts.length}',
                name: 'Switch Student',
                subtitle: '${studentAccounts.length} children linked',
                color: const Color(0xffffb866),
                onTap: _chooseStudentAccount,
              ),
              const SizedBox(height: 24),
              _MenuTile(
                icon: Icons.settings_outlined,
                label: t('settings'),
                trailing: Icon(
                  _settingsExpanded
                      ? Icons.keyboard_arrow_down_rounded
                      : Icons.chevron_right_rounded,
                  size: 19,
                  color: chocolate,
                ),
                onTap: () => setState(
                  () => _settingsExpanded = !_settingsExpanded,
                ),
              ),
              if (_settingsExpanded) ...[
                _MenuTile(
                  icon: Icons.person_outline_rounded,
                  label: t('account'),
                  onTap: () {
                    Navigator.pop(context);
                    _openProfile();
                  },
                ),
                _MenuTile(
                  icon: Icons.lock_outline_rounded,
                  label: t('privacy'),
                  onTap: () => _showMessage('Opening privacy & security…'),
                ),
                _MenuTile(
                  icon: Icons.notifications_none_rounded,
                  label: t('notifications'),
                  onTap: () => _showMessage('Opening notification settings…'),
                ),
              ],
              _MenuTile(
                icon: Icons.language_rounded,
                label: t('language'),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(languageName, style: const TextStyle(fontSize: 11)),
                    const SizedBox(width: 4),
                    const Icon(Icons.chevron_right_rounded, size: 18),
                  ],
                ),
                onTap: () {
                  Navigator.pop(context);
                  _chooseLanguage();
                },
              ),
              _MenuTile(
                icon: Icons.palette_outlined,
                label: 'Theme',
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(themeName, style: const TextStyle(fontSize: 11)),
                    const SizedBox(width: 4),
                    const Icon(Icons.chevron_right_rounded, size: 18),
                  ],
                ),
                onTap: () {
                  Navigator.pop(context);
                  _chooseTheme();
                },
              ),
              _MenuTile(
                icon: Icons.download_rounded,
                label: t('download'),
                onTap: () {
                  Navigator.pop(context);
                  _pushContent<void>(const DownloadAppPage());
                },
              ),
              _MenuTile(
                icon: Icons.logout_rounded,
                label: t('logout'),
                onTap: () async {
                  Navigator.pop(context);
                  await widget.onLogout();
                },
              ),
              const Spacer(),
              const Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  _MiniSchoolMark(),
                  SizedBox(width: 7),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'GRANDVIEW SCHOOL',
                        style: TextStyle(
                          color: chocolate,
                          fontSize: 8,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      Text(
                        "VIEW YOUR CHILD'S GENIUS",
                        style: TextStyle(fontSize: 5, color: Colors.black54),
                      ),
                    ],
                  ),
                ],
              ),
              const SizedBox(height: 10),
              const Text(
                'App Version: V1.0.0',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 9, fontWeight: FontWeight.w700),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildCarousel() {
    return ColoredBox(
      color: Colors.transparent,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 11, 12, 6),
        child: Column(
          children: [
            SizedBox(
              height: 152,
              child: PageView(
                controller: _pageController,
                onPageChanged: (value) => setState(() => _page = value),
                children: [
                  _bannerImage(),
                  _messageBanner(
                    'Keep learning!',
                    'Your next lesson is waiting.',
                    Icons.auto_stories_rounded,
                    const [Color(0xff75d7e8), Color(0xffd9f9fa)],
                  ),
                  _messageBanner(
                    'Great progress',
                    'See your latest achievements.',
                    Icons.emoji_events_rounded,
                    const [Color(0xffffd58b), Color(0xffffeee0)],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 9),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(
                3,
                (index) => AnimatedContainer(
                  duration: const Duration(milliseconds: 250),
                  width: index == _page ? 20 : 7,
                  height: 7,
                  margin: const EdgeInsets.symmetric(horizontal: 2.5),
                  decoration: BoxDecoration(
                    color: index == _page ? appStyle.accent : Colors.white38,
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _bannerImage() => ClipRRect(
        borderRadius: BorderRadius.circular(17),
        child: Image.asset('assets/welcome-back.png', fit: BoxFit.cover),
      );

  Widget _messageBanner(
    String title,
    String subtitle,
    IconData icon,
    List<Color> colors,
  ) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 1),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: colors),
        borderRadius: BorderRadius.circular(17),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(icon, size: 58, color: const Color(0xff6b3f1f)),
          const SizedBox(width: 18),
          Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w900,
                ),
              ),
              Text(subtitle, style: const TextStyle(fontSize: 11)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildTools() {
    return Container(
      margin: const EdgeInsets.only(top: 5),
      padding: const EdgeInsets.fromLTRB(16, 18, 16, 8),
      decoration: BoxDecoration(
        color: appStyle.background.withValues(alpha: .74),
        borderRadius: const BorderRadius.vertical(top: Radius.circular(23)),
      ),
      child: Column(
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      t('tools'),
                      style: TextStyle(
                        color: appStyle.accent,
                        fontSize: 10,
                        fontWeight: FontWeight.w900,
                        letterSpacing: 1.1,
                      ),
                    ),
                    Text(
                      t('need'),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 18,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              ),
              TextButton(
                onPressed: () => _showMessage('All student tools'),
                child: Text(
                  t('viewAll'),
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: tools.length,
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 3,
              mainAxisExtent: 104,
              crossAxisSpacing: 9,
              mainAxisSpacing: 10,
            ),
            itemBuilder: (_, index) => _ToolButton(
              tool: tools[index],
              onTap: () => _openTool(tools[index].label),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildNavigation() {
    const items = [
      'Home',
      'Notices',
      'Scan',
      'Payments',
      'Contact',
    ];
    final unreadPayments =
        _currentPaymentIds(context).difference(_readPaymentIds).length;
    final unreadAnnouncements = _currentAnnouncementIds(context)
        .difference(_readAnnouncementIds)
        .length;
    return SafeArea(
      minimum: const EdgeInsets.fromLTRB(14, 0, 14, 8),
      child: SizedBox(
        height: 66,
        child: Container(
          decoration: BoxDecoration(
            color: appStyle.background.withValues(alpha: .96),
            borderRadius: BorderRadius.circular(34),
            border: Border.all(color: appStyle.secondary.withValues(alpha: .8)),
            boxShadow: const [
              BoxShadow(
                color: Color(0x55000000),
                blurRadius: 14,
                offset: Offset(0, 5),
              ),
            ],
          ),
          child: Row(
            children: List.generate(items.length, (index) {
              final selected = _navIndex == index;
              return Expanded(
                child: InkWell(
                  key: index == 2
                      ? const ValueKey('persistent-scan-button')
                      : null,
                  borderRadius: BorderRadius.circular(28),
                  onTap: () {
                    setState(() => _navIndex = index);
                    if (index == 0) {
                      _contentNavigatorKey.currentState?.popUntil(
                        (route) => route.isFirst,
                      );
                    } else if (index == 1) {
                      _markAnnouncementsRead(context);
                      _pushContent<void>(const SchoolNewsPage());
                    } else if (index == 2) {
                      _pushContent<void>(const QrScannerPage());
                    } else if (index == 3) {
                      _markPaymentsRead(context);
                      _pushContent<void>(const PaymentRecordsPage());
                    } else if (index == 4) {
                      _pushContent<void>(const ContactSchoolPage());
                    }
                  },
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Stack(clipBehavior: Clip.none, children: [
                        _GeneratedAtlasIcon(
                          asset: 'assets/generated-navigation-icons.png',
                          index: index,
                          columns: 5,
                          rows: 1,
                          size: index == 2 ? 28 : 25,
                          color: selected ? appStyle.accent : Colors.white,
                        ),
                        if (index == 1 && unreadAnnouncements > 0)
                          Positioned(
                              right: -9,
                              top: -7,
                              child: Container(
                                constraints: const BoxConstraints(
                                    minWidth: 16, minHeight: 16),
                                padding:
                                    const EdgeInsets.symmetric(horizontal: 4),
                                decoration: BoxDecoration(
                                    color: const Color(0xffe63746),
                                    borderRadius: BorderRadius.circular(10),
                                    border: Border.all(color: Colors.white)),
                                alignment: Alignment.center,
                                child: Text(
                                    unreadAnnouncements > 99
                                        ? '99+'
                                        : '$unreadAnnouncements',
                                    style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 8,
                                        fontWeight: FontWeight.w900)),
                              )),
                        if (index == 3 && unreadPayments > 0)
                          Positioned(
                              right: -9,
                              top: -7,
                              child: Container(
                                constraints: const BoxConstraints(
                                    minWidth: 16, minHeight: 16),
                                padding:
                                    const EdgeInsets.symmetric(horizontal: 4),
                                decoration: BoxDecoration(
                                    color: const Color(0xffe63746),
                                    borderRadius: BorderRadius.circular(10),
                                    border: Border.all(color: Colors.white)),
                                alignment: Alignment.center,
                                child: Text(
                                    unreadPayments > 99
                                        ? '99+'
                                        : '$unreadPayments',
                                    style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 8,
                                        fontWeight: FontWeight.w900)),
                              )),
                      ]),
                      const SizedBox(height: 2),
                      Text(
                        items[index],
                        style: TextStyle(
                          fontSize: 8.2,
                          color: selected ? appStyle.accent : Colors.white,
                        ),
                      ),
                    ],
                  ),
                ),
              );
            }),
          ),
        ),
      ),
    );
  }
}

class SchoolFeesPage extends StatelessWidget {
  const SchoolFeesPage({super.key});
  static const brown = Color(0xff6b3f1f);

  @override
  Widget build(BuildContext context) {
    final student = _StudentScope.of(context);
    final profile = BackendDataScope.student(context);
    final payments = BackendDataScope.list(context, 'payments');
    final courses = <(String, String, String)>[
      if ('${profile['program'] ?? ''}'.isNotEmpty)
        ('${profile['program']}', student.grade, '${profile['balance'] ?? 0}'),
    ];
    final totalPaid = payments.fold<double>(
        0,
        (sum, row) =>
            sum + (num.tryParse('${row['amount']}')?.toDouble() ?? 0));
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: const _InnerHeader(title: 'School Fees'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
        children: [
          const Text('Student fee information and payment history',
              style: TextStyle(fontSize: 10, color: Colors.black54)),
          const Divider(),
          const Text('1. Current study programs',
              style: TextStyle(
                  color: brown, fontWeight: FontWeight.w900, fontSize: 14)),
          const SizedBox(height: 14),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Column(children: [
              ClipRRect(
                  borderRadius: BorderRadius.circular(8),
                  child: Image.asset('assets/student-avatar.png',
                      width: 76, height: 92, fit: BoxFit.cover)),
              const SizedBox(height: 5),
              Text(student.name,
                  style: const TextStyle(
                      fontWeight: FontWeight.w800, fontSize: 11)),
              Text(student.grade,
                  style: const TextStyle(fontSize: 9, color: Colors.black54)),
            ]),
            const SizedBox(width: 12),
            Expanded(
                child: Column(
                    children: courses
                        .map((course) => Padding(
                              padding: const EdgeInsets.only(bottom: 10),
                              child: Row(children: [
                                Expanded(
                                    child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: [
                                      Text(course.$1,
                                          style: const TextStyle(
                                              fontWeight: FontWeight.w800,
                                              fontSize: 10)),
                                      Text(course.$2,
                                          style: const TextStyle(
                                              fontSize: 9,
                                              color: Colors.black54)),
                                    ])),
                                const _StatusPill(
                                    label: '2026/27', color: brown),
                                const SizedBox(width: 7),
                                SizedBox(
                                    width: 49,
                                    child: Text(course.$3,
                                        textAlign: TextAlign.right,
                                        style: const TextStyle(
                                            fontWeight: FontWeight.w900,
                                            fontSize: 9))),
                              ]),
                            ))
                        .toList())),
          ]),
          const SizedBox(height: 24),
          const Text('2. Recent payment records',
              style: TextStyle(
                  color: brown, fontWeight: FontWeight.w900, fontSize: 14)),
          const SizedBox(height: 10),
          Container(
            decoration: BoxDecoration(
                border: Border.all(color: const Color(0xffd7d2ce)),
                borderRadius: BorderRadius.circular(8)),
            clipBehavior: Clip.antiAlias,
            child: Table(
              columnWidths: const {
                0: FlexColumnWidth(.6),
                1: FlexColumnWidth(2.2),
                2: FlexColumnWidth(1.4),
                3: FlexColumnWidth(1.2)
              },
              border: const TableBorder(
                  horizontalInside: BorderSide(color: Color(0xffe1dcd8))),
              children: [
                const TableRow(
                    decoration: BoxDecoration(color: brown),
                    children: [
                      _Cell('#', light: true),
                      _Cell('Description', light: true),
                      _Cell('Date', light: true),
                      _Cell('Receipt', light: true),
                    ]),
                ...List.generate(
                    payments.length,
                    (i) => TableRow(children: [
                          _Cell('${i + 1}'),
                          _Cell('${payments[i]['category'] ?? 'Payment'}'),
                          _Cell('${payments[i]['paid_at'] ?? ''}'
                              .split(' ')
                              .first),
                          const Padding(
                              padding: EdgeInsets.all(7),
                              child: Icon(Icons.download_rounded,
                                  size: 15, color: brown)),
                        ])),
              ],
            ),
          ),
          const SizedBox(height: 24),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
                color: brown, borderRadius: BorderRadius.circular(14)),
            child: Text('Total paid: ${totalPaid.toStringAsFixed(2)}',
                textAlign: TextAlign.center,
                style: TextStyle(
                    color: Colors.white, fontWeight: FontWeight.w900)),
          ),
        ],
      ),
    );
  }
}

class BackendRecordsPage extends StatelessWidget {
  final String title;
  final List<String> workflows;
  const BackendRecordsPage(
      {super.key, required this.title, required this.workflows});

  @override
  Widget build(BuildContext context) {
    final records = workflows
        .expand((workflow) => BackendDataScope.records(context, workflow)
            .map((row) => (workflow, row)))
        .toList();
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: _InnerHeader(title: title, filter: true),
      body: records.isEmpty
          ? Center(child: Text('No $title records registered in the backend.'))
          : ListView.builder(
              padding: const EdgeInsets.all(16),
              itemCount: records.length,
              itemBuilder: (context, index) {
                final workflow = records[index].$1;
                final row = records[index].$2;
                final values = row.entries
                    .where((entry) =>
                        !['id', 'Image', 'QR Image', 'updated_at']
                            .contains(entry.key) &&
                        '${entry.value}'.trim().isNotEmpty)
                    .toList();
                return Card(
                  margin: const EdgeInsets.only(bottom: 12),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(workflow.replaceAll('-', ' ').toUpperCase(),
                              style: const TextStyle(
                                  color: Color(0xff6b3f1f),
                                  fontWeight: FontWeight.w900)),
                          const Divider(),
                          ...values.map((entry) => Padding(
                              padding: const EdgeInsets.symmetric(vertical: 3),
                              child: Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Expanded(
                                        child: Text(entry.key,
                                            style: const TextStyle(
                                                fontWeight: FontWeight.w700,
                                                fontSize: 11))),
                                    Expanded(
                                        child: Text('${entry.value}',
                                            style:
                                                const TextStyle(fontSize: 11)))
                                  ]))),
                        ]),
                  ),
                );
              },
            ),
    );
  }
}

class HomeworkPage extends StatelessWidget {
  const HomeworkPage({super.key});
  static const assignments = <(String, String, String, Color)>[];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: const _InnerHeader(title: 'Homework', filter: true),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(18, 5, 18, 24),
        children: [
          const Text('Your assignments and current status',
              style: TextStyle(fontSize: 10, color: Colors.black54)),
          const SizedBox(height: 12),
          Row(children: const [
            _SummaryBox('All', '0', Color(0xff6b3f1f)),
            _SummaryBox('Done', '0', Color(0xff16815e)),
            _SummaryBox('Overdue', '0', Color(0xffdb3a3a)),
            _SummaryBox('Pending', '0', Color(0xffd39d18)),
          ]),
          const SizedBox(height: 16),
          if (assignments.isEmpty)
            const Center(
                child: Padding(
                    padding: EdgeInsets.all(24),
                    child: Text(
                        'No homework records registered in the backend.'))),
          ...assignments.map((item) => Container(
                margin: const EdgeInsets.only(bottom: 12),
                padding: const EdgeInsets.all(13),
                decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(13),
                    border: Border.all(color: const Color(0xffe3dedb)),
                    boxShadow: const [
                      BoxShadow(color: Color(0x0c000000), blurRadius: 8)
                    ]),
                child: Row(children: [
                  Expanded(
                      child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                        Text(item.$1,
                            style: const TextStyle(
                                fontSize: 12, fontWeight: FontWeight.w800)),
                        const SizedBox(height: 7),
                        Wrap(spacing: 5, runSpacing: 5, children: [
                          const Icon(Icons.calendar_month_outlined, size: 15),
                          const SizedBox(width: 5),
                          Text(item.$2, style: const TextStyle(fontSize: 10))
                        ]),
                      ])),
                  _StatusPill(label: item.$3, color: item.$4),
                ]),
              )),
        ],
      ),
    );
  }
}

class AttendancePage extends StatelessWidget {
  const AttendancePage({super.key});

  @override
  Widget build(BuildContext context) {
    final grade = _StudentScope.of(context).grade;
    final records = BackendDataScope.list(context, 'attendance').map((row) {
      final raw = '${row['state'] ?? ''}';
      final state = {
            'P': 'Present',
            'L': 'Late',
            'A': 'Absent',
            'F': 'Excused'
          }[raw] ??
          raw;
      final color = state == 'Present'
          ? const Color(0xff28945e)
          : state == 'Absent'
              ? const Color(0xffd94c4c)
              : const Color(0xffd6a928);
      final date = '${row['attendance_date'] ?? ''}';
      return ('$grade · ${row['session'] ?? ''}', date, date, state, color);
    }).toList();
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: const _InnerHeader(title: 'Attendance', filter: true),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(18, 5, 18, 90),
        children: [
          const Text('Attendance records by date',
              style: TextStyle(fontSize: 10, color: Colors.black54)),
          const Divider(),
          Container(
            height: 42,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            decoration: BoxDecoration(
              color: Colors.white,
              border: Border.all(color: const Color(0xffd7d2ce)),
              borderRadius: BorderRadius.circular(9),
            ),
            child: Row(children: [
              Expanded(
                  child: Text(
                      records.isEmpty ? 'No attendance date' : records.first.$2,
                      style: const TextStyle(fontSize: 11))),
              const Icon(Icons.calendar_month_outlined,
                  size: 17, color: Color(0xff6b3f1f)),
            ]),
          ),
          const SizedBox(height: 12),
          const Wrap(spacing: 8, runSpacing: 4, children: [
            Text('All records',
                style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800)),
            SizedBox(width: 15),
            Text('● Present   ○ Absent   ○ Excused',
                style: TextStyle(fontSize: 9, color: Colors.black54))
          ]),
          const Divider(),
          if (records.isEmpty)
            const Center(
                child: Padding(
                    padding: EdgeInsets.all(24),
                    child: Text('No attendance records registered.'))),
          ...records.map((item) => Container(
                margin: const EdgeInsets.only(bottom: 12),
                padding: const EdgeInsets.all(13),
                decoration: BoxDecoration(
                    color: const Color(0xfffaf8f6),
                    borderRadius: BorderRadius.circular(13)),
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(children: [
                        Expanded(
                            child: Text(item.$1,
                                style: const TextStyle(
                                    fontSize: 11,
                                    fontWeight: FontWeight.w800))),
                        _StatusPill(label: item.$4, color: item.$5)
                      ]),
                      const SizedBox(height: 5),
                      Row(children: [
                        const Icon(Icons.calendar_month_outlined, size: 14),
                        const SizedBox(width: 5),
                        Text('${item.$2}  until  ${item.$3}',
                            style: const TextStyle(fontSize: 9))
                      ]),
                      const SizedBox(height: 10),
                      Container(
                          height: 48,
                          width: double.infinity,
                          padding: const EdgeInsets.all(8),
                          decoration: BoxDecoration(
                              color: Colors.white,
                              border:
                                  Border.all(color: const Color(0xff8d6a50)),
                              borderRadius: BorderRadius.circular(7)),
                          child: const Text('Teacher note',
                              style: TextStyle(
                                  fontSize: 9, color: Colors.black38))),
                    ]),
              )),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
          onPressed: () {},
          backgroundColor: const Color(0xff6b3f1f),
          foregroundColor: Colors.white,
          label: const Text('Request leave', style: TextStyle(fontSize: 11))),
    );
  }
}

class _InnerHeader extends StatelessWidget implements PreferredSizeWidget {
  final String title;
  final bool filter;
  const _InnerHeader({required this.title, this.filter = false});
  @override
  Size get preferredSize => const Size.fromHeight(58);
  @override
  Widget build(BuildContext context) => AppBar(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.white,
        elevation: 0,
        leading: IconButton(
            onPressed: () => Navigator.pop(context),
            icon: const Icon(Icons.chevron_left_rounded,
                color: Color(0xff6b3f1f))),
        titleSpacing: 0,
        title: Text(title,
            style: const TextStyle(
                color: Color(0xff6b3f1f),
                fontSize: 16,
                fontWeight: FontWeight.w900)),
        actions: filter
            ? [
                Padding(
                    padding: const EdgeInsets.only(right: 12),
                    child: OutlinedButton.icon(
                        onPressed: () {},
                        icon: const Icon(Icons.tune_rounded, size: 15),
                        label: const Text('Filter'),
                        style: OutlinedButton.styleFrom(
                            foregroundColor: const Color(0xff6b3f1f),
                            visualDensity: VisualDensity.compact)))
              ]
            : null,
      );
}

class _StatusPill extends StatelessWidget {
  final String label;
  final Color color;
  const _StatusPill({required this.label, required this.color});
  @override
  Widget build(BuildContext context) => Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration:
          BoxDecoration(color: color, borderRadius: BorderRadius.circular(20)),
      child: Text(label,
          style: const TextStyle(
              color: Colors.white, fontSize: 8, fontWeight: FontWeight.w800)));
}

class _SummaryBox extends StatelessWidget {
  final String label, value;
  final Color color;
  const _SummaryBox(this.label, this.value, this.color);
  @override
  Widget build(BuildContext context) => Expanded(
      child: Container(
          height: 56,
          margin: const EdgeInsets.symmetric(horizontal: 2),
          decoration: BoxDecoration(
              border: Border.all(color: const Color(0xffe3dedb)),
              borderRadius: BorderRadius.circular(5)),
          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Text(label, style: const TextStyle(fontSize: 9)),
            Text(value,
                style: TextStyle(
                    color: color, fontWeight: FontWeight.w900, fontSize: 15))
          ])));
}

class _Cell extends StatelessWidget {
  final String text;
  final bool light;
  const _Cell(this.text, {this.light = false});
  @override
  Widget build(BuildContext context) => Padding(
      padding: const EdgeInsets.all(7),
      child: Text(text,
          style: TextStyle(
              color: light ? Colors.white : const Color(0xff443a34),
              fontSize: 8,
              fontWeight: light ? FontWeight.w800 : FontWeight.w500)));
}

class ThemeSelectionPage extends StatelessWidget {
  final StudentTheme selectedTheme;
  const ThemeSelectionPage({super.key, required this.selectedTheme});

  @override
  Widget build(BuildContext context) {
    final choices = StudentTheme.values;
    final colors = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: const _InnerHeader(title: 'Theme'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(18, 14, 18, 28),
        children: [
          Text(
            'Choose how Grandview School looks',
            style: TextStyle(fontSize: 11, color: colors.onSurfaceVariant),
          ),
          const SizedBox(height: 14),
          ...choices.map((choice) {
            final style = studentThemeStyles[choice]!;
            final selected = choice == selectedTheme;
            return Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Material(
                color: selected
                    ? colors.primaryContainer.withValues(alpha: .45)
                    : colors.surface,
                shape: RoundedRectangleBorder(
                  side: BorderSide(
                    color: selected ? colors.primary : colors.outlineVariant,
                    width: selected ? 2 : 1,
                  ),
                  borderRadius: BorderRadius.circular(12),
                ),
                clipBehavior: Clip.antiAlias,
                child: RadioListTile<StudentTheme>(
                  value: choice,
                  groupValue: selectedTheme,
                  activeColor: colors.primary,
                  onChanged: (value) => Navigator.pop(context, value),
                  secondary: Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(
                        gradient: LinearGradient(
                            colors: [style.primary, style.secondary]),
                        borderRadius: BorderRadius.circular(12)),
                    child: Icon(style.icon, color: style.accent),
                  ),
                  title: Text(
                    style.name,
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                  subtitle: Text(style.subtitle),
                ),
              ),
            );
          }),
        ],
      ),
    );
  }
}

class LanguagePage extends StatelessWidget {
  final String selectedLanguage;
  const LanguagePage({super.key, required this.selectedLanguage});

  @override
  Widget build(BuildContext context) {
    const languages = [
      ('en', 'English', 'English', 'Use the app in English'),
      ('km', 'ខ្មែរ', 'Khmer', 'ប្រើកម្មវិធីជាភាសាខ្មែរ'),
      ('zh', '中文', 'Chinese', '使用中文浏览应用'),
    ];
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: const _InnerHeader(title: 'Language'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(18, 14, 18, 28),
        children: [
          const Text('Choose your preferred language',
              style: TextStyle(fontSize: 11, color: Colors.black54)),
          const SizedBox(height: 14),
          ...languages.map((language) {
            final selected = language.$1 == selectedLanguage;
            return Container(
              margin: const EdgeInsets.only(bottom: 10),
              decoration: BoxDecoration(
                border: Border.all(
                  color: selected
                      ? const Color(0xff6b3f1f)
                      : const Color(0xffded8d4),
                  width: selected ? 2 : 1,
                ),
                borderRadius: BorderRadius.circular(11),
              ),
              child: RadioListTile<String>(
                value: language.$1,
                groupValue: selectedLanguage,
                activeColor: const Color(0xff6b3f1f),
                onChanged: (value) => Navigator.pop(context, value),
                title: Text(
                  language.$2,
                  style: TextStyle(
                    fontFamily: language.$1 == 'km' ? 'KhmerOS' : null,
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                subtitle: Text(
                  '${language.$3} • ${language.$4}',
                  style: TextStyle(
                    fontFamily: language.$1 == 'km' ? 'KhmerOS' : null,
                    fontSize: 9,
                    color: Colors.black54,
                  ),
                ),
              ),
            );
          }),
        ],
      ),
    );
  }
}

class DownloadAppPage extends StatelessWidget {
  const DownloadAppPage({super.key});
  static const androidBuildReady = false;
  static const iosBuildReady = false;
  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.white,
        appBar: const _InnerHeader(title: 'Download Grandview App'),
        body: ListView(
          padding: const EdgeInsets.fromLTRB(20, 24, 20, 30),
          children: [
            Center(
              child: Container(
                width: 105,
                height: 105,
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: Colors.white,
                  shape: BoxShape.circle,
                  border: Border.all(color: const Color(0xff6b3f1f), width: 3),
                ),
                child: ClipOval(
                  child: Image.asset('assets/grandview-logo.jpg',
                      fit: BoxFit.contain),
                ),
              ),
            ),
            const SizedBox(height: 15),
            const Text('GRANDVIEW SCHOOL',
                textAlign: TextAlign.center,
                style: TextStyle(
                    color: Color(0xff6b3f1f),
                    fontSize: 18,
                    fontWeight: FontWeight.w900)),
            const SizedBox(height: 5),
            const Text('Choose your device to install the student app.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 10, color: Colors.black54)),
            const SizedBox(height: 28),
            _DownloadCard(
              icon: Icons.android_rounded,
              title: 'Android',
              subtitle: 'Download the Grandview School APK',
              buttonLabel:
                  androidBuildReady ? 'Download APK' : 'APK build pending',
              enabled: androidBuildReady,
              onPressed: () {
                downloadAndroidApp();
                ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Android download started')));
              },
            ),
            const SizedBox(height: 13),
            _DownloadCard(
              icon: Icons.apple_rounded,
              title: 'iPhone & iPad',
              subtitle: 'Install directly from the school website',
              buttonLabel:
                  iosBuildReady ? 'Install on iOS' : 'Signed IPA pending',
              enabled: iosBuildReady,
              onPressed: () {
                installIosApp();
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                    content: Text('Opening the direct iOS installer')));
              },
            ),
            const SizedBox(height: 18),
            Container(
              padding: const EdgeInsets.all(13),
              decoration: BoxDecoration(
                  color: const Color(0xfffff7ef),
                  borderRadius: BorderRadius.circular(10)),
              child: const Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.info_outline_rounded,
                        size: 18, color: Color(0xff6b3f1f)),
                    SizedBox(width: 9),
                    Expanded(
                        child: Text(
                            'No app store is used. Android installs the APK from this website. iOS installs a signed IPA through an HTTPS manifest and school-managed Apple provisioning.',
                            style: TextStyle(
                                fontSize: 9,
                                height: 1.4,
                                color: Color(0xff5c493d)))),
                  ]),
            ),
          ],
        ),
      );
}

class _DownloadCard extends StatelessWidget {
  final IconData icon;
  final String title, subtitle, buttonLabel;
  final bool enabled;
  final VoidCallback? onPressed;
  const _DownloadCard(
      {required this.icon,
      required this.title,
      required this.subtitle,
      required this.buttonLabel,
      required this.enabled,
      required this.onPressed});
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
            color: Colors.white,
            border: Border.all(color: const Color(0xffdfd5ce)),
            borderRadius: BorderRadius.circular(13),
            boxShadow: const [
              BoxShadow(color: Color(0x0d000000), blurRadius: 8)
            ]),
        child: Row(children: [
          CircleAvatar(
              radius: 28,
              backgroundColor: const Color(0xfffff1e5),
              child: Icon(icon, color: const Color(0xff6b3f1f), size: 30)),
          const SizedBox(width: 13),
          Expanded(
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                Text(title,
                    style: const TextStyle(
                        fontSize: 14, fontWeight: FontWeight.w900)),
                const SizedBox(height: 3),
                Text(subtitle,
                    style: const TextStyle(fontSize: 9, color: Colors.black54)),
                const SizedBox(height: 10),
                _StoreDownloadBadge(
                    apple: icon == Icons.apple_rounded,
                    enabled: enabled,
                    onPressed: onPressed),
                const SizedBox(height: 5),
                Text(buttonLabel,
                    style: TextStyle(
                        fontSize: 9,
                        fontWeight: FontWeight.w700,
                        color: enabled
                            ? const Color(0xff267a48)
                            : Colors.black45))
              ])),
        ]),
      );
}

class _StoreDownloadBadge extends StatelessWidget {
  final bool apple, enabled;
  final VoidCallback? onPressed;
  const _StoreDownloadBadge(
      {required this.apple, required this.enabled, this.onPressed});

  @override
  Widget build(BuildContext context) => Opacity(
        opacity: enabled ? 1 : .48,
        child: Material(
          color: Colors.black,
          borderRadius: BorderRadius.circular(7),
          child: InkWell(
            onTap: enabled ? onPressed : null,
            borderRadius: BorderRadius.circular(7),
            child: Container(
              width: 174,
              height: 54,
              padding: const EdgeInsets.symmetric(horizontal: 11),
              decoration: BoxDecoration(
                  border: Border.all(color: Colors.white24),
                  borderRadius: BorderRadius.circular(7)),
              child: Row(children: [
                Icon(apple ? Icons.apple_rounded : Icons.play_arrow_rounded,
                    color: Colors.white, size: apple ? 31 : 34),
                const SizedBox(width: 8),
                Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(apple ? 'Download on the' : 'GET IT ON',
                          style: const TextStyle(
                              color: Colors.white,
                              fontSize: 9,
                              height: 1,
                              fontWeight: FontWeight.w500)),
                      const SizedBox(height: 3),
                      Text(apple ? 'App Store' : 'Google Play',
                          style: const TextStyle(
                              color: Colors.white,
                              fontSize: 18,
                              height: 1,
                              fontWeight: FontWeight.w600)),
                    ])
              ]),
            ),
          ),
        ),
      );
}

class QrScannerPage extends StatefulWidget {
  const QrScannerPage({super.key});
  @override
  State<QrScannerPage> createState() => _QrScannerPageState();
}

class _QrScannerPageState extends State<QrScannerPage> {
  final MobileScannerController controller = MobileScannerController(
      formats: const [BarcodeFormat.qrCode],
      detectionSpeed: DetectionSpeed.noDuplicates);
  final MobileApi api = MobileApi();
  bool torchOn = false;
  bool submitting = false;
  String message = 'Scan the school attendance QR code';

  Future<void> _detected(BarcodeCapture capture) async {
    if (submitting) return;
    final value = capture.barcodes.isEmpty
        ? ''
        : (capture.barcodes.first.rawValue?.trim() ?? '');
    if (value.isEmpty) return;
    setState(() {
      submitting = true;
      message = 'Checking attendance…';
    });
    try {
      final result = await api.scanAttendance(value);
      if (!mounted) return;
      setState(() => message =
          'Attendance recorded: ${result['session']} · ${result['state']}');
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(
              'Attendance recorded for ${result['date']} (${result['session']})'),
          backgroundColor: const Color(0xff20a958)));
      await Future<void>.delayed(const Duration(seconds: 2));
      if (mounted) Navigator.of(context).pop();
    } on MobileApiException catch (error) {
      if (mounted)
        setState(() {
          submitting = false;
          message = error.message;
        });
    } catch (_) {
      if (mounted)
        setState(() {
          submitting = false;
          message = 'Unable to record attendance';
        });
    }
  }

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        bottom: false,
        child: Stack(children: [
          Positioned.fill(
              child:
                  MobileScanner(controller: controller, onDetect: _detected)),
          Positioned.fill(child: ColoredBox(color: Color(0x44000000))),
          Center(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(message,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                      color: Color(0xffd3a16e),
                      fontSize: 17,
                      fontWeight: FontWeight.w900)),
              const SizedBox(height: 36),
              const _ScannerFrame(size: 250),
              const SizedBox(height: 26),
              Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                IconButton(
                    onPressed: null,
                    icon: const Icon(Icons.add_photo_alternate_rounded,
                        color: Colors.white)),
                const SizedBox(width: 42),
                IconButton(
                    onPressed: () async {
                      await controller.toggleTorch();
                      if (mounted) setState(() => torchOn = !torchOn);
                    },
                    icon: Icon(
                        torchOn
                            ? Icons.flash_on_rounded
                            : Icons.flash_off_rounded,
                        color:
                            torchOn ? const Color(0xffffc14d) : Colors.white)),
              ]),
            ]),
          ),
        ]),
      ),
    );
  }
}

class _ScannerFrame extends StatelessWidget {
  final double size;
  const _ScannerFrame({required this.size});
  @override
  Widget build(BuildContext context) => SizedBox(
      width: size,
      height: size,
      child: CustomPaint(painter: _ScannerFramePainter()));
}

class _ScannerFramePainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = Colors.white
      ..strokeWidth = 3
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;
    const length = 38.0;
    final paths = [
      Path()
        ..moveTo(0, length)
        ..lineTo(0, 0)
        ..lineTo(length, 0),
      Path()
        ..moveTo(size.width - length, 0)
        ..lineTo(size.width, 0)
        ..lineTo(size.width, length),
      Path()
        ..moveTo(0, size.height - length)
        ..lineTo(0, size.height)
        ..lineTo(length, size.height),
      Path()
        ..moveTo(size.width - length, size.height)
        ..lineTo(size.width, size.height)
        ..lineTo(size.width, size.height - length),
    ];
    for (final path in paths) {
      canvas.drawPath(path, paint);
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _ScannerNavItem extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  const _ScannerNavItem(this.icon, this.label, this.onTap);
  @override
  Widget build(BuildContext context) => InkWell(
      onTap: onTap,
      child: SizedBox(
          width: 54,
          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(icon, size: 21, color: const Color(0xffa99d94)),
            Text(label,
                style: const TextStyle(fontSize: 7, color: Color(0xffa99d94)))
          ])));
}

class SchoolNewsPage extends StatelessWidget {
  const SchoolNewsPage({super.key});

  String _text(dynamic value) => '$value'
      .replaceAll(RegExp(r'<br\s*/?>', caseSensitive: false), '\n')
      .replaceAll(RegExp(r'<[^>]*>'), '')
      .replaceAll('&nbsp;', ' ')
      .replaceAll('&amp;', '&')
      .replaceAll('&lt;', '<')
      .replaceAll('&gt;', '>')
      .trim();

  @override
  Widget build(BuildContext context) {
    final posts = <Map<String, dynamic>>[
      ...BackendDataScope.records(context, 'setting-announcement')
          .map((row) => {...row, '_notice_kind': 'Announcement'}),
      ...BackendDataScope.records(context, 'setting-activities')
          .map((row) => {...row, '_notice_kind': 'School Activity'}),
      ...BackendDataScope.records(context, 'pre-invoices')
          .map((row) => {...row, '_notice_kind': 'Invoice Released'}),
    ]..sort((a, b) {
        final aDate =
            '${a['released_at'] ?? a['updated_at'] ?? a['UpdatedAt'] ?? a['created_at'] ?? ''}';
        final bDate =
            '${b['released_at'] ?? b['updated_at'] ?? b['UpdatedAt'] ?? b['created_at'] ?? ''}';
        return bDate.compareTo(aDate);
      });
    return Scaffold(
      backgroundColor: const Color(0xfffbfaf9),
      appBar: const _InnerHeader(title: 'Notifications'),
      body: ListView(
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 25),
          children: [
            Text(
                '${posts.length} school notification${posts.length == 1 ? '' : 's'}',
                style: TextStyle(fontSize: 9, color: Colors.black54)),
            const Divider(height: 20),
            if (posts.isEmpty)
              const Center(
                  child: Padding(
                      padding: EdgeInsets.all(24),
                      child: Text('No school notifications available.'))),
            ...posts.map((post) {
              final kind = '${post['_notice_kind'] ?? 'Notification'}';
              final isInvoice = kind == 'Invoice Released';
              final title = isInvoice
                  ? _text(post['invoice_no'] ?? 'Invoice released')
                  : _text(post['Title'] ?? kind);
              final description = isInvoice
                  ? '${_text(post['description'] ?? 'School fee')} • ${post['currency'] ?? 'USD'} ${(num.tryParse('${post['total']}') ?? 0).toStringAsFixed(2)}'
                  : _text(post['Description'] ?? '');
              final date = _text(post['released_at'] ??
                  post['UpdatedAt'] ??
                  post['updated_at'] ??
                  post['created_at'] ??
                  post['invoice_date'] ??
                  '');
              final image =
                  '${post['Image'] ?? post['Attachment'] ?? ''}'.trim();
              return Card(
                margin: const EdgeInsets.only(bottom: 12),
                clipBehavior: Clip.antiAlias,
                color: Colors.white,
                elevation: 0,
                shape: RoundedRectangleBorder(
                    side: const BorderSide(color: Color(0xffe3dcd6)),
                    borderRadius: BorderRadius.circular(12)),
                child: InkWell(
                  onTap: isInvoice
                      ? () => Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) => PaymentDetailPage(
                                invoice: '${post['invoice_no'] ?? ''}',
                                date: '${post['invoice_date'] ?? ''}',
                                status: (num.tryParse('${post['paid']}') ??
                                            0) >=
                                        (num.tryParse('${post['total']}') ?? 0)
                                    ? 'Paid'
                                    : 'Unpaid',
                                statusColor: (num.tryParse('${post['paid']}') ??
                                            0) >=
                                        (num.tryParse('${post['total']}') ?? 0)
                                    ? const Color(0xff43b76f)
                                    : const Color(0xffe59a23),
                                description:
                                    '${post['description'] ?? 'Pre-Invoice'}',
                                amount: num.tryParse('${post['total']}')
                                        ?.toDouble() ??
                                    0,
                                currency: '${post['currency'] ?? 'USD'}',
                                preInvoiceId: '${post['id'] ?? ''}',
                                canPay: true,
                                releasedAt:
                                    '${post['released_at'] ?? post['created_at'] ?? post['invoice_date'] ?? ''}',
                                paidAt: '${post['paid_at'] ?? ''}',
                              ),
                            ),
                          )
                      : () => Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) =>
                                  AnnouncementDetailPage(post: post),
                            ),
                          ),
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (image.isNotEmpty)
                          SizedBox(
                              width: double.infinity,
                              height: 165,
                              child: backendDataImage(image,
                                  fit: BoxFit.cover,
                                  fallback: Image.asset(
                                      'assets/welcome-back.png',
                                      fit: BoxFit.cover))),
                        Padding(
                          padding: const EdgeInsets.all(13),
                          child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(children: [
                                  Icon(
                                      isInvoice
                                          ? Icons.receipt_long_outlined
                                          : kind == 'School Activity'
                                              ? Icons.event_outlined
                                              : Icons.campaign_outlined,
                                      size: 17,
                                      color: Color(0xff8a4b20)),
                                  const SizedBox(width: 7),
                                  Expanded(
                                      child: Text(title,
                                          style: const TextStyle(
                                              fontSize: 13,
                                              fontWeight: FontWeight.w900)))
                                ]),
                                const SizedBox(height: 4),
                                Text(kind.toUpperCase(),
                                    style: const TextStyle(
                                        fontSize: 8,
                                        color: Color(0xffb36a36),
                                        fontWeight: FontWeight.w900,
                                        letterSpacing: .7)),
                                if (description.isNotEmpty) ...[
                                  const SizedBox(height: 7),
                                  Text(description,
                                      maxLines: 3,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                          fontSize: 10, height: 1.4))
                                ],
                                const SizedBox(height: 9),
                                Row(children: [
                                  Text(date,
                                      style: const TextStyle(
                                          fontSize: 8,
                                          color: Color(0xff8d7666))),
                                  const Spacer(),
                                  const Text('Read more',
                                      style: TextStyle(
                                          fontSize: 9,
                                          color: Color(0xff8a4b20),
                                          fontWeight: FontWeight.w800))
                                ]),
                              ]),
                        ),
                      ]),
                ),
              );
            }),
          ]),
    );
  }
}

class AnnouncementDetailPage extends StatelessWidget {
  final Map<String, dynamic> post;
  const AnnouncementDetailPage({super.key, required this.post});
  String _plain(dynamic value) => '$value'
      .replaceAll(RegExp(r'<br\s*/?>', caseSensitive: false), '\n')
      .replaceAll(RegExp(r'<[^>]*>'), '')
      .replaceAll('&nbsp;', ' ')
      .replaceAll('&amp;', '&')
      .trim();
  @override
  Widget build(BuildContext context) {
    final title = _plain(post['Title'] ?? 'Announcement'),
        description = _plain(post['Description'] ?? ''),
        date = _plain(post['UpdatedAt'] ?? post['updated_at'] ?? '');
    final image = '${post['Image'] ?? post['Attachment'] ?? ''}'.trim();
    return Scaffold(
        backgroundColor: const Color(0xfffbfaf9),
        appBar: const _InnerHeader(title: 'Announcement'),
        body: ListView(padding: const EdgeInsets.all(16), children: [
          if (image.isNotEmpty)
            ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: SizedBox(
                    height: 210,
                    width: double.infinity,
                    child: backendDataImage(image,
                        fit: BoxFit.cover,
                        fallback: Image.asset('assets/welcome-back.png',
                            fit: BoxFit.cover)))),
          if (image.isNotEmpty) const SizedBox(height: 16),
          Text(title,
              style: const TextStyle(
                  fontSize: 20,
                  height: 1.25,
                  fontWeight: FontWeight.w900,
                  color: Color(0xff542f1b))),
          const SizedBox(height: 8),
          Row(children: [
            const Icon(Icons.schedule, size: 14, color: Colors.black45),
            const SizedBox(width: 5),
            Text(date,
                style: const TextStyle(fontSize: 9, color: Colors.black54))
          ]),
          const Divider(height: 28),
          Text(description.isEmpty ? 'No additional details.' : description,
              style: const TextStyle(fontSize: 13, height: 1.65)),
        ]));
  }
}

class PaymentRecordsPage extends StatefulWidget {
  const PaymentRecordsPage({super.key});
  @override
  State<PaymentRecordsPage> createState() => _PaymentRecordsPageState();
}

class _PaymentRecordsPageState extends State<PaymentRecordsPage> {
  final Set<String> locallyPaidInvoices = <String>{};
  final Set<String> locallyPendingInvoices = <String>{};

  @override
  Widget build(BuildContext context) {
    final preInvoices = BackendDataScope.records(context, 'pre-invoices');
    final linkedPaymentReceipts = preInvoices
        .map((row) => '${row['payment_receipt'] ?? ''}'.trim())
        .where((receipt) => receipt.isNotEmpty)
        .toSet();
    final records = BackendDataScope.list(context, 'payments')
        .where((row) =>
            !linkedPaymentReceipts.contains('${row['receipt'] ?? ''}'.trim()))
        .map((row) => (
              '${row['receipt'] ?? ''}',
              '${row['paid_at'] ?? ''}'.split(' ').first,
              'Paid',
              const Color(0xff43b76f),
              '${row['category'] ?? 'Payment'}',
              num.tryParse('${row['amount']}')?.toDouble() ?? 0,
              '${row['currency'] ?? 'USD'}',
              '',
              false,
              '${row['paid_at'] ?? ''}',
              '${row['paid_at'] ?? ''}',
            ))
        .toList()
      ..addAll(preInvoices.map((row) {
        final id = '${row['id'] ?? ''}';
        final paid = locallyPaidInvoices.contains(id) ||
            (num.tryParse('${row['paid']}') ?? 0) >=
                (num.tryParse('${row['total']}') ?? 0);
        final backendStatus = locallyPendingInvoices.contains(id)
            ? 'Pending Verification'
            : '${row['payment_status'] ?? ''}';
        final status = paid
            ? 'Paid'
            : backendStatus == 'Pending Verification'
                ? 'Pending Verification'
                : backendStatus == 'Rejected'
                    ? 'Receipt Rejected'
                    : 'Unpaid';
        return (
          '${row['invoice_no'] ?? ''}',
          '${row['invoice_date'] ?? ''}',
          status,
          paid
              ? const Color(0xff43b76f)
              : backendStatus == 'Pending Verification'
                  ? const Color(0xff3278c8)
                  : backendStatus == 'Rejected'
                      ? const Color(0xffd84b55)
                      : const Color(0xffe59a23),
          '${row['description'] ?? 'Pre-Invoice'}',
          num.tryParse('${row['total']}')?.toDouble() ?? 0,
          '${row['currency'] ?? 'USD'}',
          id,
          true,
          '${row['released_at'] ?? row['created_at'] ?? row['invoice_date'] ?? ''}',
          '${row['paid_at'] ?? ''}',
        );
      }));
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: const _InnerHeader(title: 'Payment Records', filter: true),
      body: ListView(
          padding: const EdgeInsets.fromLTRB(16, 5, 16, 25),
          children: [
            const Text('All transactions and invoice status',
                style: TextStyle(fontSize: 9, color: Colors.black54)),
            const Divider(),
            if (records.isEmpty)
              const Center(
                  child: Padding(
                      padding: EdgeInsets.all(24),
                      child: Text('No payment records registered.'))),
            ...records.map((record) => InkWell(
                  borderRadius: BorderRadius.circular(10),
                  onTap: () async {
                    final status = await Navigator.of(context)
                        .push<String>(MaterialPageRoute<String>(
                      builder: (_) => PaymentDetailPage(
                        invoice: record.$1,
                        date: record.$2,
                        status: record.$3,
                        statusColor: record.$4,
                        description: record.$5,
                        amount: record.$6,
                        currency: record.$7,
                        preInvoiceId: record.$8,
                        canPay: record.$9,
                        releasedAt: record.$10,
                        paidAt: record.$11,
                      ),
                    ));
                    if (status != null && mounted) {
                      setState(() {
                        if (status == 'Paid') {
                          locallyPaidInvoices.add(record.$8);
                        } else if (status == 'Pending Verification') {
                          locallyPendingInvoices.add(record.$8);
                        }
                      });
                    }
                  },
                  child: Container(
                    margin: const EdgeInsets.only(bottom: 10),
                    padding: const EdgeInsets.all(13),
                    decoration: BoxDecoration(
                        color: Colors.white,
                        border: Border.all(color: const Color(0xffe1dcd8)),
                        borderRadius: BorderRadius.circular(10)),
                    child: Column(children: [
                      Row(children: [
                        Expanded(
                            child: Text(record.$1,
                                style: const TextStyle(
                                    fontSize: 11,
                                    fontWeight: FontWeight.w900))),
                        _StatusPill(label: record.$3, color: record.$4)
                      ]),
                      const SizedBox(height: 7),
                      Row(children: [
                        const Icon(Icons.calendar_month_outlined, size: 14),
                        const SizedBox(width: 5),
                        Text(record.$2, style: const TextStyle(fontSize: 9)),
                        const Spacer(),
                        const Icon(Icons.chevron_right_rounded,
                            size: 17, color: Color(0xff9c8b80))
                      ]),
                      const Divider(height: 17),
                      Row(children: [
                        Text(record.$5, style: const TextStyle(fontSize: 9)),
                        const Spacer(),
                        _StatusPill(
                            label:
                                '${record.$7} ${record.$6.toStringAsFixed(2)}',
                            color: const Color(0xff6b3f1f))
                      ]),
                    ]),
                  ),
                )),
          ]),
    );
  }
}

class PaymentDetailPage extends StatefulWidget {
  final String invoice;
  final String date;
  final String status;
  final Color statusColor;
  final String description;
  final double amount;
  final String currency;
  final String preInvoiceId;
  final bool canPay;
  final String releasedAt;
  final String paidAt;
  const PaymentDetailPage(
      {super.key,
      required this.invoice,
      required this.date,
      required this.status,
      required this.statusColor,
      required this.description,
      required this.amount,
      required this.currency,
      required this.preInvoiceId,
      required this.canPay,
      required this.releasedAt,
      required this.paidAt});
  @override
  State<PaymentDetailPage> createState() => _PaymentDetailPageState();
}

class _PaymentDetailPageState extends State<PaymentDetailPage> {
  bool preparingPayment = false;
  late String currentStatus = widget.status;
  late Color currentStatusColor = widget.statusColor;
  Future<void> startBankPayment() async {
    setState(() => preparingPayment = true);
    try {
      final intent =
          await MobileApi().prepareInvoicePayment(widget.preInvoiceId);
      if (!mounted) return;
      if ('${intent['status']}' == 'Paid') {
        setState(() {
          currentStatus = 'Paid';
          currentStatusColor = const Color(0xff43b76f);
        });
        return;
      }
      final paymentStatus = await Navigator.of(context).push<String>(
        MaterialPageRoute<String>(
          builder: (_) => BankInvoicePaymentPage(intent: intent),
        ),
      );
      if (paymentStatus != null && mounted) {
        setState(() {
          currentStatus = paymentStatus;
          currentStatusColor = paymentStatus == 'Paid'
              ? const Color(0xff43b76f)
              : const Color(0xff3278c8);
        });
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
              content: Text(paymentStatus == 'Paid'
                  ? 'Payment completed and synced with the school.'
                  : 'Receipt submitted for school verification.')),
        );
        Navigator.pop(context, paymentStatus);
      }
    } catch (error) {
      if (mounted)
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.toString())));
    } finally {
      if (mounted) setState(() => preparingPayment = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.white,
        appBar: const _InnerHeader(title: 'Payment Detail'),
        body: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
            children: [
              Container(
                  padding: const EdgeInsets.all(15),
                  decoration: BoxDecoration(
                      color: const Color(0xfffffbf7),
                      border: Border.all(color: const Color(0xffdfd5ce)),
                      borderRadius: BorderRadius.circular(12)),
                  child: Column(children: [
                    Row(children: [
                      Expanded(
                          child: Text(widget.invoice,
                              style: const TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.w900,
                                  color: Color(0xff6b3f1f)))),
                      _StatusPill(
                          label: currentStatus, color: currentStatusColor)
                    ]),
                    const SizedBox(height: 12),
                    _DetailLine('Invoice date', widget.date),
                    _DetailLine('Student',
                        '${_StudentScope.of(context).name} • ${_StudentScope.of(context).studentId}'),
                    _DetailLine('Description', widget.description),
                    const Divider(height: 25),
                    Row(children: [
                      const Text('Total',
                          style: TextStyle(fontWeight: FontWeight.w900)),
                      const Spacer(),
                      Text(
                          '${widget.currency} ${widget.amount.toStringAsFixed(2)}',
                          style: const TextStyle(
                              color: Color(0xff6b3f1f),
                              fontSize: 16,
                              fontWeight: FontWeight.w900))
                    ]),
                  ])),
              const SizedBox(height: 18),
              _PaymentProcessTimeline(
                paid: currentStatus == 'Paid',
                releasedAt: widget.releasedAt,
                paidAt: widget.paidAt,
              ),
              const SizedBox(height: 18),
              SystemInvoicePreview(
                invoice: widget.invoice,
                date: widget.date,
                description: widget.description,
                amount: widget.amount,
                currency: widget.currency,
              ),
              const SizedBox(height: 18),
              if (widget.canPay && currentStatus != 'Paid') ...[
                FilledButton.icon(
                  onPressed: preparingPayment ? null : startBankPayment,
                  icon: preparingPayment
                      ? const SizedBox(
                          width: 17,
                          height: 17,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.account_balance_rounded),
                  label: Text(preparingPayment
                      ? 'Preparing secure payment…'
                      : 'Pay now with bank account'),
                  style: FilledButton.styleFrom(
                    backgroundColor: const Color(0xff126f93),
                    padding: const EdgeInsets.all(14),
                  ),
                ),
                const SizedBox(height: 12),
              ],
              OutlinedButton.icon(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => PaymentCertificatePage(
                      student: _StudentScope.of(context),
                      invoice: widget.invoice,
                      date: widget.date,
                      status: currentStatus,
                      amount: widget.amount,
                      currency: widget.currency,
                    ),
                  ),
                ),
                icon: const Icon(Icons.receipt_long_outlined),
                label: const Text('View Payment Certificate'),
              ),
              const SizedBox(height: 8),
              /* Removed legacy invoice-request controls.
                      label: Text(
                          submitting ? 'Sending…' : 'Send Invoice Request'),
                      style: FilledButton.styleFrom(
                          backgroundColor: const Color(0xff6b3f1f),
                          padding: const EdgeInsets.all(14))),
                  */
            ]),
      );
}

class _PaymentProcessTimeline extends StatelessWidget {
  final bool paid;
  final String releasedAt;
  final String paidAt;

  const _PaymentProcessTimeline({
    required this.paid,
    required this.releasedAt,
    required this.paidAt,
  });

  String _formatDateTime(String value) {
    final normalized = value.trim().replaceFirst(' ', 'T');
    final parsed = DateTime.tryParse(normalized);
    if (parsed == null)
      return value.trim().isEmpty ? 'Date unavailable' : value;
    const months = <String>[
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec'
    ];
    final hour = parsed.hour % 12 == 0 ? 12 : parsed.hour % 12;
    final minute = parsed.minute.toString().padLeft(2, '0');
    final period = parsed.hour < 12 ? 'AM' : 'PM';
    return '${parsed.day.toString().padLeft(2, '0')} ${months[parsed.month - 1]} ${parsed.year}  $hour:$minute $period';
  }

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: const Color(0xfff8fbfd),
          border: Border.all(color: const Color(0xffd6e0e7)),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Payment Process',
              style: TextStyle(fontSize: 15, fontWeight: FontWeight.w900)),
          const SizedBox(height: 15),
          _PaymentProcessStep(
            icon: Icons.description_outlined,
            title: 'Invoice Released',
            dateTime: _formatDateTime(releasedAt),
            complete: true,
          ),
          Container(
            width: 2,
            height: 18,
            margin: const EdgeInsets.only(left: 17),
            color: paid ? const Color(0xff43b76f) : const Color(0xffd4d9dd),
          ),
          _PaymentProcessStep(
            icon: paid ? Icons.check_rounded : Icons.schedule_rounded,
            title: 'Paid',
            dateTime: paid
                ? _formatDateTime(paidAt)
                : 'Waiting for payment confirmation',
            complete: paid,
          ),
        ]),
      );
}

class _PaymentProcessStep extends StatelessWidget {
  final IconData icon;
  final String title;
  final String dateTime;
  final bool complete;

  const _PaymentProcessStep({
    required this.icon,
    required this.title,
    required this.dateTime,
    required this.complete,
  });

  @override
  Widget build(BuildContext context) => Row(children: [
        Container(
          width: 36,
          height: 36,
          decoration: BoxDecoration(
            color: complete ? const Color(0xff43b76f) : const Color(0xffe5e9ec),
            shape: BoxShape.circle,
          ),
          child: Icon(icon,
              size: 19, color: complete ? Colors.white : Colors.black45),
        ),
        const SizedBox(width: 12),
        Expanded(
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title,
                style:
                    const TextStyle(fontSize: 13, fontWeight: FontWeight.w800)),
            const SizedBox(height: 3),
            Text(dateTime,
                style: TextStyle(
                    fontSize: 11,
                    color:
                        complete ? const Color(0xff52616a) : Colors.black45)),
          ]),
        ),
      ]);
}

class BankInvoicePaymentPage extends StatefulWidget {
  final Map<String, dynamic> intent;
  const BankInvoicePaymentPage({super.key, required this.intent});

  @override
  State<BankInvoicePaymentPage> createState() => _BankInvoicePaymentPageState();
}

class _BankInvoicePaymentPageState extends State<BankInvoicePaymentPage> {
  bool completing = false;
  Uint8List? receiptBytes;
  String receiptMime = 'image/jpeg';

  Map<String, dynamic> get bank =>
      Map<String, dynamic>.from(widget.intent['bank'] as Map? ?? const {});

  String value(String key, [String fallback = '—']) {
    final result = '${bank[key] ?? ''}'.trim();
    return result.isEmpty ? fallback : result;
  }

  Future<void> copyText(String text, String label) async {
    await Clipboard.setData(ClipboardData(text: text));
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('$label copied')),
      );
    }
  }

  Future<void> pickReceipt() async {
    final image = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      imageQuality: 82,
      maxWidth: 1600,
    );
    if (image == null) return;
    final bytes = await image.readAsBytes();
    if (bytes.length > 5 * 1024 * 1024) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('Receipt image must be 5 MB or smaller')),
        );
      }
      return;
    }
    final lower = image.name.toLowerCase();
    setState(() {
      receiptBytes = bytes;
      receiptMime = lower.endsWith('.png')
          ? 'image/png'
          : lower.endsWith('.webp')
              ? 'image/webp'
              : 'image/jpeg';
    });
  }

  Future<void> submitReceipt() async {
    if (receiptBytes == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Upload your bank payment receipt first')),
      );
      return;
    }
    setState(() => completing = true);
    try {
      await MobileApi().uploadPaymentProof(
        invoiceId: '${widget.intent['invoice_id'] ?? ''}',
        reference: '${widget.intent['reference'] ?? ''}',
        receiptImage: 'data:$receiptMime;base64,${base64Encode(receiptBytes!)}',
      );
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        barrierDismissible: false,
        builder: (dialogContext) => AlertDialog(
          icon: const Icon(Icons.hourglass_top_rounded,
              color: Color(0xff3278c8), size: 54),
          title: const Text('Receipt submitted'),
          content: const Text(
            'The school will verify your bank receipt. The invoice status is now Pending Verification.',
            textAlign: TextAlign.center,
          ),
          actions: [
            FilledButton(
              onPressed: () => Navigator.pop(dialogContext),
              child: const Text('Done'),
            ),
          ],
        ),
      );
      if (mounted) Navigator.pop(context, 'Pending Verification');
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.toString())));
      }
    } finally {
      if (mounted) setState(() => completing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final qr = value('QR Image', '');
    final reference = '${widget.intent['reference'] ?? ''}';
    final paymentLink = '${widget.intent['payment_link'] ?? ''}';
    final amount = num.tryParse('${widget.intent['amount']}') ?? 0;
    final currency = '${widget.intent['currency'] ?? 'USD'}';
    return Scaffold(
      backgroundColor: const Color(0xfff4f8fb),
      appBar: const _InnerHeader(title: 'Bank Payment'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(18, 18, 18, 120),
        children: [
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(18),
              boxShadow: const [
                BoxShadow(
                    color: Color(0x14042f49),
                    blurRadius: 18,
                    offset: Offset(0, 7)),
              ],
            ),
            child: Column(
              children: [
                const Icon(Icons.verified_user_rounded,
                    color: Color(0xff126f93), size: 30),
                const SizedBox(height: 8),
                const Text('Secure school payment',
                    style:
                        TextStyle(fontSize: 17, fontWeight: FontWeight.w900)),
                Text('${widget.intent['invoice_no'] ?? ''}',
                    style:
                        const TextStyle(fontSize: 10, color: Colors.black54)),
                const SizedBox(height: 18),
                if (qr.isNotEmpty)
                  SizedBox(
                    height: 210,
                    child: backendDataImage(qr,
                        fit: BoxFit.contain,
                        fallback:
                            const Icon(Icons.qr_code_2_rounded, size: 180)),
                  )
                else
                  const Icon(Icons.qr_code_2_rounded, size: 180),
                const SizedBox(height: 12),
                Text('$currency ${amount.toStringAsFixed(2)}',
                    style: const TextStyle(
                        color: Color(0xff126f93),
                        fontSize: 25,
                        fontWeight: FontWeight.w900)),
                const SizedBox(height: 18),
                _DetailLine('Bank', value('Bank Name')),
                _DetailLine('Account name', value('Account Name')),
                _DetailLine('Account number', value('Account Number')),
                _DetailLine('Currency', value('Currency', currency)),
                _DetailLine('Reference', reference),
                const SizedBox(height: 10),
                OutlinedButton.icon(
                  onPressed: () => copyText(reference, 'Payment reference'),
                  icon: const Icon(Icons.copy_rounded, size: 17),
                  label: const Text('Copy payment reference'),
                ),
                if (paymentLink.isNotEmpty)
                  TextButton.icon(
                    onPressed: () => copyText(paymentLink, 'Payment link'),
                    icon: const Icon(Icons.link_rounded, size: 17),
                    label: const Text('Copy bank payment link'),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          const Text(
            'Transfer the exact amount using the QR or bank account above. Then upload the bank receipt. The school must approve it before the invoice becomes Paid.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 11, height: 1.5, color: Colors.black54),
          ),
          const SizedBox(height: 16),
          OutlinedButton.icon(
            onPressed: completing ? null : pickReceipt,
            icon: const Icon(Icons.add_photo_alternate_outlined),
            label: Text(receiptBytes == null
                ? 'Upload payment receipt'
                : 'Change payment receipt'),
            style: OutlinedButton.styleFrom(padding: const EdgeInsets.all(14)),
          ),
          const SizedBox(height: 12),
          FilledButton.icon(
            onPressed: completing ? null : submitReceipt,
            icon: completing
                ? const SizedBox(
                    width: 17,
                    height: 17,
                    child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.check_circle_outline_rounded),
            label: Text(completing
                ? 'Uploading receipt…'
                : 'Confirm Paid & Submit Receipt'),
            style: FilledButton.styleFrom(
              backgroundColor: const Color(0xff35a864),
              padding: const EdgeInsets.all(15),
            ),
          ),
          if (receiptBytes != null) ...[
            const SizedBox(height: 16),
            ClipRRect(
              borderRadius: BorderRadius.circular(12),
              child: Image.memory(receiptBytes!,
                  height: 210, width: double.infinity, fit: BoxFit.contain),
            ),
          ],
        ],
      ),
    );
  }
}

class SystemInvoicePreview extends StatelessWidget {
  final String invoice;
  final String date;
  final String description;
  final double amount;
  final String currency;
  const SystemInvoicePreview(
      {super.key,
      required this.invoice,
      required this.date,
      required this.description,
      required this.amount,
      required this.currency});

  String _value(Map<String, dynamic> row, List<String> keys,
      [String fallback = '—']) {
    for (final key in keys) {
      final value = '${row[key] ?? ''}'.trim();
      if (value.isNotEmpty) return value;
    }
    return fallback;
  }

  @override
  Widget build(BuildContext context) {
    final student = _StudentScope.of(context);
    final profile = BackendDataScope.student(context);
    final general = backendFirstRecord(context, 'setting-general');
    final banks = BackendDataScope.records(context, 'setting-bank-account');
    Map<String, dynamic> bank = const {};
    for (final row in banks) {
      final active = '${row['Status'] ?? 'Active'}'.toLowerCase() != 'inactive';
      if (active &&
          '${row['Currency'] ?? currency}'.toUpperCase() ==
              currency.toUpperCase()) {
        bank = row;
        break;
      }
    }
    if (bank.isEmpty) {
      for (final row in banks) {
        if ('${row['Status'] ?? 'Active'}'.toLowerCase() != 'inactive') {
          bank = row;
          break;
        }
      }
    }
    final logo = _value(general, ['companyInvoice', 'companyLogo'], '');
    final qr = _value(bank, ['QR Image'], '');
    final money = '$currency ${amount.toStringAsFixed(2)}';
    const border = BorderSide(color: Color(0xffc8d9ec));
    Widget info(String label, String value) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: Text.rich(
              TextSpan(children: [
                TextSpan(
                    text: '$label: ',
                    style: const TextStyle(fontWeight: FontWeight.w800)),
                TextSpan(text: value)
              ]),
              style: const TextStyle(fontSize: 9)),
        );
    Widget total(String label, String value) => Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
          decoration: const BoxDecoration(border: Border(bottom: border)),
          child: Row(children: [
            Expanded(
                child: Text(label,
                    textAlign: TextAlign.right,
                    style: const TextStyle(fontSize: 8))),
            const Spacer(),
            Text(value, style: const TextStyle(fontSize: 8))
          ]),
        );
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: const Color(0xffd8dde5)),
          borderRadius: BorderRadius.circular(8)),
      child: Column(children: [
        SizedBox(
            height: 115,
            child: logo.isNotEmpty
                ? backendDataImage(logo,
                    fit: BoxFit.contain, fallback: backendSchoolLogo(size: 110))
                : backendSchoolLogo(size: 110)),
        const Text('Proforma Invoice',
            style: TextStyle(fontSize: 18, color: Color(0xff20283b))),
        const Divider(height: 24),
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                info('Student', student.name),
                info('Gender', _value(profile, ['gender', 'Gender'])),
                info('Time', _value(profile, ['time', 'Time'])),
                info('Room', _value(profile, ['room', 'Room'])),
              ])),
          const SizedBox(width: 8),
          Expanded(
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                info('Invoice', invoice),
                info('Date', date),
                info('Phone',
                    _value(profile, ['phone', 'main_contact', 'contact'])),
              ])),
        ]),
        const SizedBox(height: 10),
        Table(
          border: TableBorder.all(color: const Color(0xffc8d9ec)),
          columnWidths: const {
            0: FlexColumnWidth(2.2),
            1: FlexColumnWidth(1.4),
            2: FlexColumnWidth(.8),
            3: FlexColumnWidth(1.3)
          },
          children: [
            const TableRow(
                decoration: BoxDecoration(color: Color(0xffeaf2ff)),
                children: [
                  _InvoiceCell('DESCRIPTION', bold: true),
                  _InvoiceCell('LEVEL', bold: true),
                  _InvoiceCell('QTY', bold: true),
                  _InvoiceCell('TOTAL', bold: true),
                ]),
            TableRow(children: [
              _InvoiceCell(description),
              _InvoiceCell(
                  student.grade.isEmpty ? 'Unassigned' : student.grade),
              const _InvoiceCell('1'),
              _InvoiceCell(money)
            ]),
          ],
        ),
        total('Sub Total', money),
        total('Discount', '$currency 0.00'),
        total('Grand Total', money),
        total('Amount Paid', money),
        const SizedBox(height: 14),
        if (bank.isNotEmpty) ...[
          SizedBox(
              height: 125,
              child: qr.isEmpty
                  ? const Icon(Icons.qr_code_2, size: 110)
                  : backendDataImage(qr,
                      fit: BoxFit.contain,
                      fallback: const Icon(Icons.qr_code_2, size: 110))),
          Text(_value(bank, ['Bank Name'], 'Bank Account'),
              style: const TextStyle(fontWeight: FontWeight.w900)),
          Text(
              '${_value(bank, ['Account Name'], '')} · ${_value(bank, [
                    'Account Number'
                  ], '')} · ${_value(bank, ['Currency'], currency)}',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 9)),
        ] else
          const Text('Bank QR is not configured in School Settings',
              style: TextStyle(fontSize: 9, color: Colors.redAccent)),
        const SizedBox(height: 14),
        Text(
            [
              _value(general, ['@Company Address', 'companyAddress', 'address'],
                  ''),
              _value(general, ['@Phone Number', 'phone'], ''),
              _value(general, ['@Company Email', 'email'], '')
            ].where((v) => v.isNotEmpty).join('  ·  '),
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 7)),
        const SizedBox(height: 18),
        const Text('Cashier Signature',
            style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800)),
      ]),
    );
  }
}

class _InvoiceCell extends StatelessWidget {
  final String text;
  final bool bold;
  const _InvoiceCell(this.text, {this.bold = false});
  @override
  Widget build(BuildContext context) => Padding(
      padding: const EdgeInsets.all(5),
      child: Text(text,
          style: TextStyle(
              fontSize: 7,
              fontWeight: bold ? FontWeight.w800 : FontWeight.normal)));
}

class PaymentCertificatePage extends StatelessWidget {
  final StudentAccount student;
  final String invoice;
  final String date;
  final String status;
  final double amount;
  final String currency;
  const PaymentCertificatePage({
    super.key,
    required this.student,
    required this.invoice,
    required this.date,
    required this.status,
    required this.amount,
    required this.currency,
  });

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: const _InnerHeader(title: 'Payment Certificate'),
        body: ListView(
          padding: const EdgeInsets.all(22),
          children: [
            Container(
              padding: const EdgeInsets.all(22),
              decoration: BoxDecoration(
                color: const Color(0xfffffcf8),
                border: Border.all(color: const Color(0xffd8c5b5)),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Column(
                children: [
                  Image.asset('assets/grandview-logo.jpg',
                      width: 65, height: 65),
                  const SizedBox(height: 10),
                  const Text('GRANDVIEW SCHOOL',
                      style: TextStyle(
                          color: Color(0xff6b3f1f),
                          fontSize: 16,
                          fontWeight: FontWeight.w900)),
                  const Text('Official Payment Certificate',
                      style: TextStyle(fontSize: 11)),
                  const Divider(height: 28),
                  _DetailLine('Invoice', invoice),
                  _DetailLine('Student', student.name),
                  _DetailLine('Student ID', student.studentId),
                  _DetailLine('Grade / Class', student.grade),
                  _DetailLine('Payment date', date),
                  _DetailLine('Status', status),
                  _DetailLine(
                      'Amount', '$currency ${amount.toStringAsFixed(2)}'),
                  const Divider(height: 28),
                  const Text(
                    'This document confirms the payment record registered for the student above.',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 10, color: Colors.black54),
                  ),
                ],
              ),
            ),
          ],
        ),
      );
}

class _DetailLine extends StatelessWidget {
  final String label, value;
  const _DetailLine(this.label, this.value);
  @override
  Widget build(BuildContext context) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(
            width: 105,
            child: Text(label,
                style: const TextStyle(fontSize: 9, color: Colors.black54))),
        Expanded(
            child: Text(value,
                textAlign: TextAlign.right,
                style:
                    const TextStyle(fontSize: 10, fontWeight: FontWeight.w700)))
      ]));
}

class ContactSchoolPage extends StatelessWidget {
  const ContactSchoolPage({super.key});

  Future<void> _openContactUri(BuildContext context, Uri uri) async {
    try {
      if (await launchUrl(uri, mode: LaunchMode.externalApplication)) return;
    } catch (_) {}
    if (!context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(uri.scheme == 'tel'
            ? 'No phone app is available on this device.'
            : 'No email app is available on this device.')));
  }

  String _field(Map<String, dynamic> data, List<String> keys,
      [String fallback = 'Not configured']) {
    for (final key in keys) {
      final value = '${data[key] ?? ''}'.trim();
      if (value.isNotEmpty) return value;
    }
    return fallback;
  }

  @override
  Widget build(BuildContext context) {
    final school = backendFirstRecord(context, 'setting-general');
    final nameEnglish =
        _field(school, ['companyNameEng', 'School Name (English)'], 'School');
    final nameKhmer =
        _field(school, ['companyName', 'School Name (Khmer)'], '');
    final addressLine1 =
        _field(school, ['companyAddress', '@Company Address'], '');
    final addressLine2 =
        _field(school, ['companyAddressLine2', '@Company Address Line 2'], '');
    final address = [addressLine1, addressLine2]
        .where((value) => value.isNotEmpty)
        .join(', ');
    final country = _field(school, ['companyCountry', '@Company Country'], '');
    final phone = _field(school, ['phoneNumber', '@Phone Number']);
    final phone2 =
        _field(school, ['phoneNumber2', '@Phone Number 2'], '');
    final email = _field(school, ['companyEmail', '@Company Email']);
    final website = _field(school, ['companyWebsite', '@Website']);
    final map = _field(school, ['urlMap', '@URL Map']);
    final timezone = _field(school, ['timezone', '@Timezone'], '');
    final logo = _field(school, ['companyLogo'], '');
    final location = [address, country]
        .where((value) => value.isNotEmpty && value != 'Not configured')
        .join(', ');
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: _InnerHeader(title: 'Contact $nameEnglish'),
      body: ListView(
          padding: const EdgeInsets.fromLTRB(16, 5, 16, 25),
          children: [
            Center(
                child: Column(children: [
              SizedBox(
                  height: 76,
                  child: logo.isNotEmpty
                      ? backendDataImage(logo,
                          fit: BoxFit.contain,
                          fallback: backendSchoolLogo(size: 72))
                      : backendSchoolLogo(size: 72)),
              const SizedBox(height: 7),
              Text(nameEnglish,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w900,
                      color: Color(0xff6b3f1f))),
              if (nameKhmer.isNotEmpty)
                Text(nameKhmer,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontSize: 12, fontWeight: FontWeight.w700)),
            ])),
            const SizedBox(height: 14),
            const Text('Contact the school administration',
                style: TextStyle(fontSize: 9, color: Colors.black54)),
            const Divider(),
            _ContactTile(Icons.location_on_outlined, 'Visit Us',
                location.isEmpty ? 'Not configured' : location),
            Container(
                height: 38,
                margin: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                    color: const Color(0xfffff1e5),
                    borderRadius: BorderRadius.circular(20)),
                child: Text(
                    map == 'Not configured'
                        ? 'Google Maps URL is not configured'
                        : 'Open in Google Maps',
                    style: const TextStyle(
                        color: Color(0xff6b3f1f), fontSize: 9))),
            _ContactTile(Icons.phone_outlined, 'Phone', phone),
            if (phone2.isNotEmpty)
              _ContactTile(Icons.phone_outlined, 'Phone 2', phone2),
            _ContactTile(Icons.email_outlined, 'Email', email),
            _ContactTile(Icons.language_rounded, 'Website', website),
            if (timezone.isNotEmpty)
              _ContactTile(Icons.schedule_outlined, 'Timezone', timezone),
            const SizedBox(height: 4),
            Row(children: [
              Expanded(
                  child: OutlinedButton.icon(
                      onPressed: phone == 'Not configured'
                          ? null
                          : () => _openContactUri(
                              context,
                              Uri(
                                  scheme: 'tel',
                                  path: phone.replaceAll(
                                      RegExp(r'[^0-9+]'), ''))),
                      icon: const Icon(Icons.phone_outlined, size: 14),
                      label: const Text('Call Now',
                          style: TextStyle(fontSize: 9)))),
              const SizedBox(width: 8),
              Expanded(
                  child: OutlinedButton.icon(
                      onPressed: email == 'Not configured'
                          ? null
                          : () => _openContactUri(
                              context, Uri(scheme: 'mailto', path: email)),
                      icon: const Icon(Icons.email_outlined, size: 14),
                      label: const Text('Send Email',
                          style: TextStyle(fontSize: 9))))
            ]),
            if (phone2.isNotEmpty) ...[
              const SizedBox(height: 8),
              SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                      onPressed: () => _openContactUri(
                          context,
                          Uri(
                              scheme: 'tel',
                              path: phone2.replaceAll(
                                  RegExp(r'[^0-9+]'), ''))),
                      icon: const Icon(Icons.phone_outlined, size: 14),
                      label: const Text('Call Phone 2',
                          style: TextStyle(fontSize: 9))))
            ],
          ]),
    );
  }
}

class _ContactTile extends StatelessWidget {
  final IconData icon;
  final String title, value;
  const _ContactTile(this.icon, this.title, this.value);
  @override
  Widget build(BuildContext context) => Container(
      margin: const EdgeInsets.only(bottom: 9),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
          border: Border.all(color: const Color(0xffe1dcd8)),
          borderRadius: BorderRadius.circular(10)),
      child: Row(children: [
        CircleAvatar(
            backgroundColor: const Color(0xfffff1e5),
            child: Icon(icon, color: const Color(0xff6b3f1f), size: 19)),
        const SizedBox(width: 10),
        Expanded(
            child:
                Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title,
              style: const TextStyle(
                  color: Color(0xff6b3f1f),
                  fontSize: 9,
                  fontWeight: FontWeight.w900)),
          Text(value, style: const TextStyle(fontSize: 9))
        ]))
      ]));
}

class StudentReportPage extends StatefulWidget {
  const StudentReportPage({super.key});
  @override
  State<StudentReportPage> createState() => _StudentReportPageState();
}

class _StudentReportPageState extends State<StudentReportPage> {
  int period = 0;
  final feedback = TextEditingController();
  @override
  void dispose() {
    feedback.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    const brown = Color(0xff6b3f1f);
    final student = _StudentScope.of(context);
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: const _InnerHeader(title: 'Report Student'),
      body: ListView(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 28),
          children: [
            const Text("Track your child's study progress",
                style: TextStyle(fontSize: 9, color: Colors.black54)),
            const Divider(),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                  border: Border.all(color: const Color(0xffded8d4)),
                  borderRadius: BorderRadius.circular(9)),
              child: Row(children: [
                Container(
                    width: 66,
                    height: 66,
                    padding: const EdgeInsets.all(3),
                    decoration: const BoxDecoration(
                        shape: BoxShape.circle, color: brown),
                    child: ClipOval(
                        child: Image.asset('assets/student-avatar.png',
                            fit: BoxFit.cover))),
                const SizedBox(width: 12),
                Expanded(
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                      Text(student.name,
                          style: const TextStyle(
                              color: brown,
                              fontWeight: FontWeight.w900,
                              fontSize: 14)),
                      const SizedBox(height: 6),
                      Text(student.grade, style: const TextStyle(fontSize: 10)),
                      const SizedBox(height: 5),
                      Text('Student ID: ${student.studentId}',
                          style: const TextStyle(
                              fontSize: 9, color: Colors.black54))
                    ])),
              ]),
            ),
            const SizedBox(height: 10),
            Row(
                children: List.generate(
                    3,
                    (i) => Expanded(
                            child: InkWell(
                          onTap: () => setState(() => period = i),
                          child: Container(
                              padding: const EdgeInsets.symmetric(vertical: 11),
                              decoration: BoxDecoration(
                                  color: period == i ? brown : Colors.white,
                                  borderRadius: BorderRadius.circular(5)),
                              child: Text(
                                  ['Monthly', 'Semester', 'Annually'][i],
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                      fontSize: 9,
                                      color: period == i
                                          ? Colors.white
                                          : Colors.black87,
                                      fontWeight: FontWeight.w700))),
                        )))),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                  child: _RankCard(
                      icon: Icons.menu_book_rounded,
                      color: brown,
                      title: 'Chinese English',
                      score: '96.00',
                      rank: '#2 / 40',
                      onTap: () => Navigator.of(context).push(
                          MaterialPageRoute<void>(
                              builder: (_) => const GradeReportsPage())))),
              const SizedBox(width: 8),
              Expanded(
                  child: _RankCard(
                      icon: Icons.auto_stories_rounded,
                      color: Color(0xffd7a326),
                      title: 'Khmer',
                      score: '96.50',
                      rank: '#3 / 40',
                      onTap: () => Navigator.of(context).push(
                          MaterialPageRoute<void>(
                              builder: (_) => const GradeReportsPage())))),
            ]),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(13),
              decoration: BoxDecoration(
                  border: Border.all(color: const Color(0xffded8d4)),
                  borderRadius: BorderRadius.circular(9)),
              child: const Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Student Evaluation',
                        style: TextStyle(
                            color: brown,
                            fontWeight: FontWeight.w900,
                            fontSize: 13)),
                    Divider(),
                    _EvaluationRow(
                        'Responsibility', 'Excellent', Color(0xff279765)),
                    _EvaluationRow(
                        'Class participation', 'Very good', Color(0xff3a8fc3)),
                    _EvaluationRow(
                        'Homework quality', 'Good', Color(0xffd39820)),
                  ]),
            ),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                  border: Border.all(color: const Color(0xffded8d4)),
                  borderRadius: BorderRadius.circular(9)),
              child: const Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Icon(Icons.school_outlined, size: 16, color: brown),
                      SizedBox(width: 6),
                      Text("Teacher's Evaluation",
                          style: TextStyle(
                              fontSize: 10, fontWeight: FontWeight.w800)),
                      Spacer(),
                      Text('July 24, 2026', style: TextStyle(fontSize: 8))
                    ]),
                    SizedBox(height: 9),
                    ColoredBox(
                        color: Color(0xfffff7ef),
                        child: Padding(
                            padding: EdgeInsets.all(11),
                            child: Text(
                                'The student has shown excellent progress, participates actively, and demonstrates a positive attitude toward learning.',
                                style: TextStyle(
                                    fontSize: 9, color: Colors.black54)))),
                  ]),
            ),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                  border: Border.all(color: const Color(0xffded8d4)),
                  borderRadius: BorderRadius.circular(9)),
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Parent Feedback',
                        style: TextStyle(
                            fontSize: 10, fontWeight: FontWeight.w800)),
                    const SizedBox(height: 8),
                    TextField(
                        controller: feedback,
                        maxLines: 3,
                        style: const TextStyle(fontSize: 10),
                        decoration: InputDecoration(
                            hintText: 'Write your comment here…',
                            hintStyle: const TextStyle(fontSize: 9),
                            border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(7)))),
                    const SizedBox(height: 8),
                    Align(
                        alignment: Alignment.centerRight,
                        child: FilledButton(
                            onPressed: () => ScaffoldMessenger.of(context)
                                .showSnackBar(const SnackBar(
                                    content: Text('Feedback submitted'))),
                            style:
                                FilledButton.styleFrom(backgroundColor: brown),
                            child: const Text('Submit Feedback',
                                style: TextStyle(fontSize: 9)))),
                  ]),
            ),
          ]),
    );
  }
}

class _RankCard extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String title, score, rank;
  final VoidCallback onTap;
  const _RankCard(
      {required this.icon,
      required this.color,
      required this.title,
      required this.score,
      required this.rank,
      required this.onTap});
  @override
  Widget build(BuildContext context) => InkWell(
      onTap: onTap,
      child: Container(
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
              color: const Color(0xfffffbf6),
              borderRadius: BorderRadius.circular(8)),
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              CircleAvatar(
                  radius: 13,
                  backgroundColor: color,
                  child: Icon(icon, color: Colors.white, size: 13)),
              const SizedBox(width: 7),
              Expanded(
                  child: Text(title,
                      style: const TextStyle(
                          fontSize: 9, fontWeight: FontWeight.w800))),
              const Icon(Icons.chevron_right_rounded, size: 14)
            ]),
            const SizedBox(height: 11),
            Row(children: [
              Expanded(
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                    const Text('AVG Point', style: TextStyle(fontSize: 7)),
                    Text(score,
                        style: const TextStyle(
                            fontSize: 10, fontWeight: FontWeight.w800))
                  ])),
              Expanded(
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                    const Text('Rank', style: TextStyle(fontSize: 7)),
                    Text(rank,
                        style: const TextStyle(
                            fontSize: 10, fontWeight: FontWeight.w800))
                  ]))
            ])
          ])));
}

class _EvaluationRow extends StatelessWidget {
  final String label, result;
  final Color color;
  const _EvaluationRow(this.label, this.result, this.color);
  @override
  Widget build(BuildContext context) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(children: [
        Expanded(child: Text(label, style: const TextStyle(fontSize: 9))),
        Text(result,
            style: TextStyle(
                fontSize: 9, color: color, fontWeight: FontWeight.w800))
      ]));
}

class ClassesPage extends StatelessWidget {
  const ClassesPage({super.key});
  static const works = [
    (
      'Math Worksheet Chapter 4',
      'Complete all questions on multiplication practice.',
      'Complete',
      Color(0xff36ad72)
    ),
    (
      'Khmer Review lesson 1',
      'Complete all questions on multiplication practice.',
      'Pending',
      Color(0xffd5a42a)
    ),
    (
      'Science Review lesson 1',
      'Read chapter 5 and answer the questions.',
      'Pending',
      Color(0xffd5a42a)
    )
  ];
  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.white,
        appBar: const _InnerHeader(title: 'My Classes'),
        body: ListView(
            padding: const EdgeInsets.fromLTRB(16, 6, 16, 25),
            children: [
              Container(
                  padding: const EdgeInsets.all(15),
                  decoration: BoxDecoration(
                      color: const Color(0xff6b3f1f),
                      borderRadius: BorderRadius.circular(12)),
                  child: Row(children: [
                    const CircleAvatar(
                        backgroundColor: Colors.white24,
                        child: Icon(Icons.groups_rounded, color: Colors.white)),
                    const SizedBox(width: 12),
                    Expanded(
                        child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                          Text('Grandview ${_StudentScope.of(context).grade}',
                              style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 13,
                                  fontWeight: FontWeight.w900)),
                          const Text('Active classroom and assignments',
                              style:
                                  TextStyle(color: Colors.white70, fontSize: 9))
                        ]))
                  ])),
              const SizedBox(height: 9),
              Row(children: const [
                _ClassCount(Icons.menu_book_rounded, '3', 'Subjects'),
                SizedBox(width: 7),
                _ClassCount(Icons.task_alt_rounded, '1', 'Completed'),
                SizedBox(width: 7),
                _ClassCount(Icons.schedule_rounded, '2', 'Pending')
              ]),
              const SizedBox(height: 14),
              const Text('Homework',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w900)),
              const SizedBox(height: 8),
              ...works.map((work) => Container(
                  margin: const EdgeInsets.only(bottom: 10),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                      color: const Color(0xfffdfcfb),
                      borderRadius: BorderRadius.circular(12),
                      boxShadow: const [
                        BoxShadow(color: Color(0x0e000000), blurRadius: 8)
                      ]),
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(children: [
                          const Icon(Icons.calculate_outlined,
                              color: Color(0xff6b3f1f), size: 20),
                          const SizedBox(width: 9),
                          Expanded(
                              child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                Text(work.$1,
                                    style: const TextStyle(
                                        fontSize: 11,
                                        fontWeight: FontWeight.w900)),
                                Text(work.$2,
                                    style: const TextStyle(
                                        fontSize: 8, color: Colors.black54))
                              ]))
                        ]),
                        const SizedBox(height: 8),
                        const Row(children: [
                          Icon(Icons.person_outline, size: 13),
                          SizedBox(width: 4),
                          Text('Teacher', style: TextStyle(fontSize: 8)),
                          SizedBox(width: 12),
                          Icon(Icons.calendar_month_outlined, size: 13),
                          SizedBox(width: 4),
                          Text('30 May 2026', style: TextStyle(fontSize: 8))
                        ]),
                        const SizedBox(height: 8),
                        Wrap(spacing: 5, runSpacing: 5, children: [
                          OutlinedButton(
                              onPressed: () {},
                              child: const Text('View Homework',
                                  style: TextStyle(fontSize: 8))),
                          const SizedBox(width: 5),
                          OutlinedButton(
                              onPressed: () {},
                              child: const Text('Send Homework',
                                  style: TextStyle(fontSize: 8))),
                          const SizedBox(width: 4),
                          _StatusPill(label: work.$3, color: work.$4)
                        ]),
                      ]))),
            ]),
      );
}

class _ClassCount extends StatelessWidget {
  final IconData icon;
  final String count, label;
  const _ClassCount(this.icon, this.count, this.label);
  @override
  Widget build(BuildContext context) => Expanded(
      child: Container(
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
              color: const Color(0xff6b3f1f),
              borderRadius: BorderRadius.circular(6)),
          child: Row(children: [
            Icon(icon, color: Colors.white, size: 20),
            const SizedBox(width: 7),
            Column(children: [
              Text(count,
                  style: const TextStyle(
                      color: Colors.white,
                      fontSize: 16,
                      fontWeight: FontWeight.w900)),
              Text(label,
                  style: const TextStyle(color: Colors.white70, fontSize: 7))
            ])
          ])));
}

class TimetablePage extends StatefulWidget {
  const TimetablePage({super.key});
  @override
  State<TimetablePage> createState() => _TimetablePageState();
}

class _TimetablePageState extends State<TimetablePage> {
  int selectedDay = 0;
  static const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  static const lessons = [
    ('07:00 – 09:00', 'Mathematics', 'Room 101 • Mr. Dara'),
    ('09:15 – 10:45', 'English', 'Room 203 • Ms. Lina'),
    ('11:00 – 13:00', 'Science', 'Laboratory • Mr. Vannak'),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: const _InnerHeader(title: 'Class Timetable'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
        children: [
          const Text('Your weekly classroom schedule',
              style: TextStyle(fontSize: 10, color: Colors.black54)),
          const Divider(),
          const SizedBox(height: 6),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
                children: List.generate(
                    days.length,
                    (index) => Padding(
                          padding: const EdgeInsets.only(right: 7),
                          child: ChoiceChip(
                            selected: selectedDay == index,
                            onSelected: (_) =>
                                setState(() => selectedDay = index),
                            label: Text(days[index]),
                            selectedColor: const Color(0xff6b3f1f),
                            labelStyle: TextStyle(
                                fontSize: 10,
                                color: selectedDay == index
                                    ? Colors.white
                                    : const Color(0xff6b3f1f)),
                            side: const BorderSide(color: Color(0xff6b3f1f)),
                            showCheckmark: false,
                          ),
                        ))),
          ),
          const SizedBox(height: 18),
          ...lessons.asMap().entries.map((entry) {
            final lesson = entry.value;
            return Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child:
                  Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                SizedBox(
                    width: 78,
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Lesson ${entry.key + 1}',
                              style: const TextStyle(
                                  fontSize: 10, fontWeight: FontWeight.w800)),
                          const SizedBox(height: 4),
                          Text(lesson.$1,
                              style: const TextStyle(
                                  fontSize: 9, color: Colors.black54)),
                        ])),
                Expanded(
                    child: Container(
                  padding: const EdgeInsets.all(13),
                  decoration: BoxDecoration(
                      color: const Color(0xfff7f4f2),
                      borderRadius: BorderRadius.circular(12)),
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(lesson.$2,
                            style: const TextStyle(
                                fontSize: 12, fontWeight: FontWeight.w900)),
                        const SizedBox(height: 5),
                        Text(lesson.$3,
                            style: const TextStyle(
                                fontSize: 9, color: Colors.black54)),
                      ]),
                )),
              ]),
            );
          }),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            decoration: BoxDecoration(
                color: const Color(0xff6b3f1f),
                borderRadius: BorderRadius.circular(7)),
            child: const Row(children: [
              Icon(Icons.access_time_rounded, color: Colors.white, size: 15),
              SizedBox(width: 8),
              Text('Break time: 10:45 – 11:00',
                  style: TextStyle(
                      color: Colors.white,
                      fontSize: 10,
                      fontWeight: FontWeight.w800))
            ]),
          ),
        ],
      ),
    );
  }
}

class GradeReportsPage extends StatelessWidget {
  const GradeReportsPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xfffbfaf9),
      appBar: const _InnerHeader(title: 'Grade Reports'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 30),
        children: [
          const Text('Programs',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w900)),
          const SizedBox(height: 10),
          _ProgramCard(
              name: 'Chinese English',
              subtitle: 'Chinese & English language program',
              onMonthly: () => _openMonthly(context),
              onSemester: () => _openComparison(
                  context, 'Semester', 'First Quarter', 'Second Quarter'),
              onAnnually: () => _openComparison(
                  context, 'Annually', 'Semester 1', 'Semester 2')),
          _ProgramCard(
              name: 'Khmer',
              subtitle: 'Khmer language program',
              onMonthly: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                      builder: (_) => const KhmerMonthlyReportPage())),
              onSemester: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                      builder: (_) => const KhmerPeriodReportPage(
                          title: 'Semester',
                          period: 'Semester 1 in 2026–2027'))),
              onAnnually: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                      builder: (_) => const KhmerPeriodReportPage(
                          title: 'Annually', period: 'Year 2026–2027')))),
          const SizedBox(height: 13),
          const Text('Recent Reports',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w900)),
          const SizedBox(height: 8),
          _ReportTile(
              title: 'Chinese English – Monthly Exam',
              date: 'May 2026',
              score: '3.67 GPA',
              onTap: () => _openMonthly(context)),
          _ReportTile(
              title: 'Khmer – Semester',
              date: 'July 2025',
              score: '4.00 GPA',
              onTap: () => _openMonthly(context)),
        ],
      ),
    );
  }

  void _openMonthly(BuildContext context) => Navigator.of(context)
      .push(MaterialPageRoute<void>(builder: (_) => const MonthlyReportPage()));

  void _openComparison(
          BuildContext context, String title, String left, String right) =>
      Navigator.of(context).push(MaterialPageRoute<void>(
          builder: (_) => ComparisonReportPage(
              title: title, leftHeader: left, rightHeader: right)));
}

class _ProgramCard extends StatelessWidget {
  final String name, subtitle;
  final VoidCallback onMonthly;
  final VoidCallback onSemester;
  final VoidCallback onAnnually;
  const _ProgramCard(
      {required this.name,
      required this.subtitle,
      required this.onMonthly,
      required this.onSemester,
      required this.onAnnually});
  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(13),
        decoration: BoxDecoration(
            color: Colors.white,
            border: Border.all(color: const Color(0xffe8e1dc)),
            borderRadius: BorderRadius.circular(10)),
        child: Column(children: [
          Row(children: [
            const CircleAvatar(
                radius: 19,
                backgroundColor: Color(0xff6b3f1f),
                child: Icon(Icons.menu_book_rounded,
                    color: Colors.white, size: 18)),
            const SizedBox(width: 10),
            Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  Text(name,
                      style: const TextStyle(
                          color: Color(0xff6b3f1f),
                          fontSize: 12,
                          fontWeight: FontWeight.w900)),
                  Text(subtitle,
                      style:
                          const TextStyle(fontSize: 8, color: Colors.black45))
                ])),
          ]),
          const SizedBox(height: 12),
          Row(children: [
            _ReportButton('Monthly', Icons.calendar_month_outlined, onMonthly),
            const SizedBox(width: 7),
            _ReportButton('Semester', Icons.date_range_outlined, onSemester),
            const SizedBox(width: 7),
            _ReportButton('Annually', Icons.event_note_outlined, onAnnually),
          ]),
        ]),
      );
}

class _ReportButton extends StatelessWidget {
  final String label;
  final IconData icon;
  final VoidCallback onTap;
  const _ReportButton(this.label, this.icon, this.onTap);
  @override
  Widget build(BuildContext context) => Expanded(
      child: FilledButton.icon(
          onPressed: onTap,
          icon: Icon(icon, size: 13),
          label: Text(label, style: const TextStyle(fontSize: 8)),
          style: FilledButton.styleFrom(
              backgroundColor: const Color(0xff6b3f1f),
              padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 8),
              visualDensity: VisualDensity.compact,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(6)))));
}

class _ReportTile extends StatelessWidget {
  final String title, date, score;
  final VoidCallback onTap;
  const _ReportTile(
      {required this.title,
      required this.date,
      required this.score,
      required this.onTap});
  @override
  Widget build(BuildContext context) => InkWell(
      onTap: onTap,
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(11),
        decoration: BoxDecoration(
            color: Colors.white,
            border: Border.all(color: const Color(0xffe8e1dc)),
            borderRadius: BorderRadius.circular(8)),
        child: Row(children: [
          const Icon(Icons.description_outlined,
              color: Color(0xff6b3f1f), size: 20),
          const SizedBox(width: 10),
          Expanded(
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                Text(title,
                    style: const TextStyle(
                        fontSize: 10, fontWeight: FontWeight.w800)),
                Text(date,
                    style: const TextStyle(fontSize: 8, color: Colors.black45))
              ])),
          Container(
              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
              decoration: BoxDecoration(
                  color: const Color(0xfff0e6dc),
                  borderRadius: BorderRadius.circular(8)),
              child: Text(score,
                  style:
                      const TextStyle(color: Color(0xff6b3f1f), fontSize: 8)))
        ]),
      ));
}

class ComparisonReportPage extends StatelessWidget {
  final String title;
  final String leftHeader;
  final String rightHeader;
  const ComparisonReportPage(
      {super.key,
      required this.title,
      required this.leftHeader,
      required this.rightHeader});
  static const rows = [
    ('Reading', '100', 'Reading', '100'),
    ('Writing', '100', 'Writing', '100'),
    ('Speaking', '100', 'Speaking', '100'),
    ('Listening', '100', 'Listening', '100'),
    ('Quiz', '100', 'Quiz', '100'),
    ('Home', '100', 'Home', '100'),
    ('Art', '100', 'Art', '100'),
  ];

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.white,
        appBar: _InnerHeader(title: title),
        body: ListView(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 25),
            children: [
              _ReportIdentity(
                  period: title == 'Annually' ? '2026–2027' : 'Semester'),
              Table(
                border: TableBorder.all(color: const Color(0xffd7d2ce)),
                columnWidths: const {
                  0: FlexColumnWidth(.42),
                  1: FlexColumnWidth(1.15),
                  2: FlexColumnWidth(.65),
                  3: FlexColumnWidth(.42),
                  4: FlexColumnWidth(1.15),
                  5: FlexColumnWidth(.65)
                },
                children: [
                  TableRow(
                      decoration: const BoxDecoration(color: Color(0xff6b3f1f)),
                      children: [
                        _Cell(leftHeader, light: true),
                        const _Cell('Scores', light: true),
                        const _Cell(''),
                        _Cell(rightHeader, light: true),
                        const _Cell('Scores', light: true),
                        const _Cell('')
                      ]),
                  ...rows.asMap().entries.map((e) => TableRow(children: [
                        _Cell('${e.key + 1}'),
                        _Cell(e.value.$1),
                        _Cell(e.value.$2),
                        _Cell('${e.key + 1}'),
                        _Cell(e.value.$3),
                        _Cell(e.value.$4)
                      ])),
                  const TableRow(
                      decoration: BoxDecoration(color: Color(0xfff0e6dc)),
                      children: [
                        _Cell(''),
                        _Cell('Total'),
                        _Cell('1000'),
                        _Cell(''),
                        _Cell('Total'),
                        _Cell('1000')
                      ]),
                ],
              ),
              const _ReportTotals(),
            ]),
      );
}

class KhmerPeriodReportPage extends StatelessWidget {
  final String title;
  final String period;
  const KhmerPeriodReportPage(
      {super.key, required this.title, required this.period});

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.white,
        appBar: _InnerHeader(title: title),
        body: ListView(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 25),
          children: [
            _ReportIdentity(period: period),
            Table(
              border: TableBorder.all(color: const Color(0xffd7d2ce)),
              columnWidths: const {
                0: FlexColumnWidth(.45),
                1: FlexColumnWidth(1.4),
                2: FlexColumnWidth(2.4),
                3: FlexColumnWidth(.7),
              },
              children: [
                const TableRow(
                  decoration: BoxDecoration(color: Color(0xff6b3f1f)),
                  children: [
                    _Cell('#', light: true),
                    _Cell('Khmer', light: true),
                    _Cell('Subject', light: true),
                    _Cell('Scores', light: true)
                  ],
                ),
                ...KhmerMonthlyReportPage.subjects
                    .asMap()
                    .entries
                    .map((entry) => TableRow(children: [
                          _Cell('${entry.key + 1}'),
                          const _Cell('Khmer'),
                          _Cell(entry.value),
                          const _Cell('100')
                        ])),
                const TableRow(
                  decoration: BoxDecoration(color: Color(0xfff0e6dc)),
                  children: [
                    _Cell(''),
                    _Cell('Total'),
                    _Cell('Total'),
                    _Cell('1100')
                  ],
                ),
              ],
            ),
            const _ReportTotals(),
          ],
        ),
      );
}

class KhmerMonthlyReportPage extends StatelessWidget {
  const KhmerMonthlyReportPage({super.key});
  static const subjects = [
    'Khmer',
    'Math',
    'History',
    'Biology',
    'English',
    'Science',
    'Computer',
    'Chemistry',
    'Geography',
    'Earth Science',
    'Sport'
  ];
  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.white,
        appBar: const _InnerHeader(title: 'Monthly'),
        body: ListView(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 25),
            children: [
              const _ReportIdentity(period: 'Monthly in 2026–2027'),
              Table(
                  border: TableBorder.all(color: const Color(0xffd7d2ce)),
                  columnWidths: const {
                    0: FlexColumnWidth(.45),
                    1: FlexColumnWidth(1.4),
                    2: FlexColumnWidth(2.4),
                    3: FlexColumnWidth(.7)
                  },
                  children: [
                    const TableRow(
                        decoration: BoxDecoration(color: Color(0xff6b3f1f)),
                        children: [
                          _Cell('#', light: true),
                          _Cell('Khmer', light: true),
                          _Cell('Subject', light: true),
                          _Cell('Scores', light: true)
                        ]),
                    ...subjects.asMap().entries.map((e) => TableRow(children: [
                          _Cell('${e.key + 1}'),
                          const _Cell('Khmer'),
                          _Cell(e.value),
                          const _Cell('100')
                        ])),
                    const TableRow(
                        decoration: BoxDecoration(color: Color(0xfff0e6dc)),
                        children: [
                          _Cell(''),
                          _Cell('Total'),
                          _Cell('Total'),
                          _Cell('1100')
                        ]),
                  ]),
              const _ReportTotals(),
            ]),
      );
}

class _ReportIdentity extends StatelessWidget {
  final String period;
  const _ReportIdentity({required this.period});
  @override
  Widget build(BuildContext context) => Container(
        decoration:
            BoxDecoration(border: Border.all(color: const Color(0xffd7d2ce))),
        child: Column(children: [
          ColoredBox(
              color: const Color(0xff6b3f1f),
              child: SizedBox(
                  width: double.infinity,
                  child: Padding(
                      padding: const EdgeInsets.all(6),
                      child: Text(period,
                          textAlign: TextAlign.center,
                          style: const TextStyle(
                              color: Colors.white, fontSize: 9))))),
          Padding(
              padding: const EdgeInsets.all(8),
              child: Row(children: [
                Expanded(
                    child: Text('Name: ${_StudentScope.of(context).name}',
                        style: const TextStyle(fontSize: 8))),
                const Expanded(
                    child: Text('Sex: Female', style: TextStyle(fontSize: 8)))
              ])),
          Padding(
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 8),
              child: Row(children: [
                Expanded(
                    child: Text('ID: ${_StudentScope.of(context).studentId}',
                        style: const TextStyle(fontSize: 8))),
                Expanded(
                    child: Text('Level: ${_StudentScope.of(context).grade}',
                        style: const TextStyle(fontSize: 8)))
              ])),
        ]),
      );
}

class _ReportTotals extends StatelessWidget {
  const _ReportTotals();
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(10),
        color: const Color(0xfffff7ef),
        child: const Column(children: [
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('Total Score', style: TextStyle(fontSize: 9)),
            Text('1000', style: TextStyle(fontSize: 9)),
            Text('Grade: A+', style: TextStyle(fontSize: 9))
          ]),
          SizedBox(height: 7),
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('Total Average', style: TextStyle(fontSize: 9)),
            Text('100.0', style: TextStyle(fontSize: 9)),
            Text('GPA: 4.00', style: TextStyle(fontSize: 9))
          ]),
        ]),
      );
}

class MonthlyReportPage extends StatelessWidget {
  const MonthlyReportPage({super.key});
  static const rows = [
    ('English', 'Class Work Completion', '100'),
    ('English', 'Homework Completion', '100'),
    ('English', 'Student Punctuality', '100'),
    ('English', 'Work Independently', '100'),
    ('English', 'Task Responsibility', '100'),
    ('English', 'Student Attendance', '100'),
    ('English', 'Respectful in the class', '100'),
    ('English', 'Morality', '100'),
    ('English', 'Reflection', '100'),
    ('English', 'Communication', '100'),
  ];
  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.white,
        appBar: const _InnerHeader(title: 'Monthly Report'),
        body: ListView(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 25),
            children: [
              Row(
                children: ['English', 'Chinese', 'Math']
                    .map(
                      (subject) => Expanded(
                        child: Container(
                          margin: const EdgeInsets.symmetric(horizontal: 2),
                          padding: const EdgeInsets.all(8),
                          decoration: BoxDecoration(
                            color: subject == 'English'
                                ? const Color(0xff6b3f1f)
                                : Colors.white,
                            border: Border.all(color: const Color(0xffe3dedb)),
                            borderRadius: BorderRadius.circular(5),
                          ),
                          child: Text(
                            subject,
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              fontSize: 9,
                              color: subject == 'English'
                                  ? Colors.white
                                  : Colors.black87,
                            ),
                          ),
                        ),
                      ),
                    )
                    .toList(),
              ),
              const SizedBox(height: 12),
              const _ReportHeader(),
              Table(
                  border: TableBorder.all(color: const Color(0xffd7d2ce)),
                  columnWidths: const {
                    0: FlexColumnWidth(.45),
                    1: FlexColumnWidth(1.1),
                    2: FlexColumnWidth(2.8),
                    3: FlexColumnWidth(.7)
                  },
                  children: [
                    const TableRow(
                        decoration: BoxDecoration(color: Color(0xff6b3f1f)),
                        children: [
                          _Cell('#', light: true),
                          _Cell('Subject', light: true),
                          _Cell('Class Work', light: true),
                          _Cell('Score', light: true)
                        ]),
                    ...rows.asMap().entries.map((e) => TableRow(children: [
                          _Cell('${e.key + 1}'),
                          _Cell(e.value.$1),
                          _Cell(e.value.$2),
                          _Cell(e.value.$3)
                        ])),
                    const TableRow(
                        decoration: BoxDecoration(color: Color(0xfff0e6dc)),
                        children: [
                          _Cell(''),
                          _Cell(''),
                          _Cell('Total'),
                          _Cell('1000')
                        ]),
                  ]),
              Container(
                  padding: const EdgeInsets.all(10),
                  color: const Color(0xfffff7ef),
                  child: const Column(children: [
                    Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text('Total Score', style: TextStyle(fontSize: 9)),
                          Text('1000', style: TextStyle(fontSize: 9)),
                          Text('Grade: A+', style: TextStyle(fontSize: 9))
                        ]),
                    SizedBox(height: 7),
                    Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text('Total Average', style: TextStyle(fontSize: 9)),
                          Text('100.0', style: TextStyle(fontSize: 9)),
                          Text('GPA: 4.00', style: TextStyle(fontSize: 9))
                        ])
                  ])),
            ]),
      );
}

class _ReportHeader extends StatelessWidget {
  const _ReportHeader();
  @override
  Widget build(BuildContext context) => Container(
        decoration:
            BoxDecoration(border: Border.all(color: const Color(0xffd7d2ce))),
        child: Column(children: [
          const ColoredBox(
              color: Color(0xff6b3f1f),
              child: SizedBox(
                  width: double.infinity,
                  child: Padding(
                      padding: EdgeInsets.all(6),
                      child: Text('Monthly 2026–2027',
                          textAlign: TextAlign.center,
                          style:
                              TextStyle(color: Colors.white, fontSize: 9))))),
          Padding(
              padding: const EdgeInsets.all(8),
              child: Row(children: [
                Expanded(
                    child: Text('Name: ${_StudentScope.of(context).name}',
                        style: const TextStyle(fontSize: 8))),
                const Expanded(
                    child: Text('Sex: Female', style: TextStyle(fontSize: 8)))
              ])),
          Padding(
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 8),
              child: Row(children: [
                Expanded(
                    child: Text('ID: ${_StudentScope.of(context).studentId}',
                        style: const TextStyle(fontSize: 8))),
                Expanded(
                    child: Text('Level: ${_StudentScope.of(context).grade}',
                        style: const TextStyle(fontSize: 8)))
              ]))
        ]),
      );
}

class StudentProfilePage extends StatelessWidget {
  final StudentAccount student;
  const StudentProfilePage({super.key, required this.student});

  static const chocolate = Color(0xff6b3f1f);
  @override
  Widget build(BuildContext context) {
    final profile = BackendDataScope.student(context);
    final details = <(String, String)>[
      ('Student ID', student.studentId),
      ('Full name', student.name),
      ('Name (Khmer)', '${profile['name_khmer'] ?? ''}'),
      ('Name (Chinese)', '${profile['name_chinese'] ?? ''}'),
      ('Date of birth', '${profile['birth_date'] ?? ''}'),
      ('Gender', '${profile['gender'] ?? ''}'),
      ('Program', '${profile['program'] ?? ''}'),
      ('Class', student.grade),
      ('Time', '${profile['time'] ?? ''}'),
      ('Room', '${profile['room'] ?? ''}'),
      ('Guardian', '${profile['guardian_name'] ?? profile['guardian'] ?? ''}'),
      ('Phone', '${profile['phone'] ?? ''}'),
      ('Admission date', '${profile['admission_date'] ?? ''}'),
      ('Status', '${profile['status'] ?? ''}'),
    ];
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.white,
        elevation: 0,
        leadingWidth: 42,
        leading: IconButton(
          onPressed: () => Navigator.pop(context),
          icon: const Icon(Icons.chevron_left_rounded, color: chocolate),
        ),
        titleSpacing: 0,
        title: const Text(
          'Student Information',
          style: TextStyle(
            color: chocolate,
            fontSize: 15,
            fontWeight: FontWeight.w800,
          ),
        ),
      ),
      body: SafeArea(
        top: false,
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(17, 22, 17, 30),
          child: Column(
            children: [
              Container(
                width: 126,
                height: 126,
                padding: const EdgeInsets.all(5),
                decoration: const BoxDecoration(
                  shape: BoxShape.circle,
                  color: chocolate,
                ),
                child: ClipOval(
                  child: Image.asset(
                    'assets/student-avatar.png',
                    fit: BoxFit.cover,
                  ),
                ),
              ),
              const SizedBox(height: 22),
              Container(
                decoration: BoxDecoration(
                  border: Border.all(color: const Color(0xffd7d2ce)),
                  borderRadius: BorderRadius.circular(2),
                ),
                child: Column(
                  children: List.generate(details.length, (index) {
                    final detail = details[index];
                    return Container(
                      height: 40,
                      decoration: BoxDecoration(
                        color: index.isEven
                            ? const Color(0xfffffdfb)
                            : const Color(0xfffbf7f3),
                        border: index == details.length - 1
                            ? null
                            : const Border(
                                bottom: BorderSide(color: Color(0xffd7d2ce)),
                              ),
                      ),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Expanded(
                            flex: 6,
                            child: Padding(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 13,
                                vertical: 10,
                              ),
                              child: Text(
                                detail.$1,
                                style: const TextStyle(
                                  color: Color(0xff5d514a),
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ),
                          Container(width: 1, color: const Color(0xffd7d2ce)),
                          Expanded(
                            flex: 5,
                            child: Padding(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 13,
                                vertical: 10,
                              ),
                              child: Text(
                                detail.$2,
                                style: const TextStyle(
                                  color: Color(0xff302a26),
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                    );
                  }),
                ),
              ),
              const SizedBox(height: 22),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => StudentIdCardPage(student: student),
                        ),
                      ),
                      icon: const Icon(Icons.badge_outlined, size: 18),
                      label: const Text('Student ID Card'),
                    ),
                  ),
                  const SizedBox(width: 9),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => EnrollmentCertificatePage(
                            student: student,
                          ),
                        ),
                      ),
                      icon: const Icon(Icons.workspace_premium_outlined,
                          size: 18),
                      label: const Text('Certificate'),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 18),
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Image.asset(
                    'assets/grandview-logo.jpg',
                    width: 32,
                    height: 32,
                    fit: BoxFit.contain,
                  ),
                  const SizedBox(width: 8),
                  const Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'GRANDVIEW SCHOOL',
                        style: TextStyle(
                          color: chocolate,
                          fontSize: 9,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      Text(
                        "VIEW YOUR CHILD'S GENIUS",
                        style: TextStyle(fontSize: 6, color: Colors.black54),
                      ),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class StudentIdCardPage extends StatelessWidget {
  final StudentAccount student;
  const StudentIdCardPage({super.key, required this.student});

  @override
  Widget build(BuildContext context) {
    final backendSvg = '${BackendDataScope.of(context)['id_card_svg'] ?? ''}';
    if (backendSvg.isNotEmpty) {
      return Scaffold(
        appBar: const _InnerHeader(title: 'Student ID Card'),
        body: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Center(
              child: SizedBox(
                  width: 300,
                  height: 480,
                  child: SvgPicture.string(backendSvg, fit: BoxFit.contain))),
        ),
      );
    }
    final profile = BackendDataScope.student(context);
    final registeredName =
        '${profile['name_english'] ?? profile['name'] ?? student.name}'.trim();
    final registeredClass = '${profile['class'] ?? student.grade}'.trim();
    final session = backendAcademicYear(context);
    final joinDate =
        '${profile['admission_date'] ?? profile['created_at'] ?? ''}'
            .split(' ')
            .first;
    const brown = Color(0xff8a4700);
    return Scaffold(
      appBar: const _InnerHeader(title: 'Student ID Card'),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Center(
          child: Container(
            width: 300,
            height: 480,
            clipBehavior: Clip.antiAlias,
            decoration: BoxDecoration(
                color: brown,
                borderRadius: BorderRadius.circular(24),
                boxShadow: const [
                  BoxShadow(color: Color(0x44000000), blurRadius: 18)
                ]),
            child: Stack(children: [
              Positioned.fill(
                  child:
                      CustomPaint(painter: const _BackendIdCardPainter(brown))),
              Positioned(
                left: 50,
                right: 44,
                top: 22,
                child: Row(children: [
                  backendSchoolLogo(size: 45),
                  const SizedBox(width: 8),
                  const Expanded(
                      child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                        Text('សាលារៀន ហ្គ្រែនវីយូ',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                                color: brown,
                                fontFamily: 'KhmerOS',
                                fontSize: 15,
                                fontWeight: FontWeight.w900)),
                        Text('GRANDVIEW SCHOOL',
                            maxLines: 1,
                            style: TextStyle(
                                color: brown,
                                fontSize: 14,
                                fontWeight: FontWeight.w900)),
                        Text('STUDENT ID CARD',
                            style: TextStyle(
                                color: brown,
                                fontSize: 9,
                                letterSpacing: 1.2,
                                fontWeight: FontWeight.w800)),
                      ])),
                ]),
              ),
              Positioned(
                top: 112,
                left: 88,
                child: Container(
                    width: 124,
                    height: 154,
                    padding: const EdgeInsets.all(3),
                    decoration: BoxDecoration(
                        color: Colors.white,
                        border: Border.all(color: brown, width: 3),
                        borderRadius: BorderRadius.circular(4)),
                    child: ClipRRect(
                        borderRadius: BorderRadius.circular(2),
                        child: backendStudentPhoto(context, size: 118))),
              ),
              Positioned(
                left: 20,
                right: 20,
                top: 275,
                child: Column(children: [
                  Text(registeredName.toUpperCase(),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 21,
                          fontWeight: FontWeight.w900)),
                  const SizedBox(height: 4),
                  const Text('Student',
                      style: TextStyle(color: Colors.white, fontSize: 15)),
                  Container(
                      width: 96,
                      margin: const EdgeInsets.symmetric(vertical: 10),
                      decoration: const BoxDecoration(
                          border:
                              Border(top: BorderSide(color: Colors.white)))),
                  SizedBox(
                      width: 198,
                      child: Column(children: [
                        _IdCardLine('ID', student.studentId),
                        _IdCardLine(
                            'Class',
                            registeredClass.isEmpty
                                ? 'Unassigned'
                                : registeredClass),
                        _IdCardLine('Session', session),
                        _IdCardLine(
                            'Join Date', joinDate.isEmpty ? '—' : joinDate),
                      ])),
                ]),
              ),
              Positioned(
                  left: 75,
                  bottom: 17,
                  child: SizedBox(
                      width: 150,
                      child: Wrap(
                          spacing: 6,
                          runSpacing: 5,
                          children: List.generate(
                              36,
                              (_) => Container(
                                  width: 6,
                                  height: 6,
                                  decoration: const BoxDecoration(
                                      color: Colors.white,
                                      shape: BoxShape.circle)))))),
            ]),
          ),
        ),
      ),
    );
  }
}

class _BackendIdCardPainter extends CustomPainter {
  final Color brown;
  const _BackendIdCardPainter(this.brown);
  @override
  void paint(Canvas canvas, Size size) {
    final white = Paint()..color = Colors.white;
    final brownPaint = Paint()..color = brown;
    final top = Path()
      ..moveTo(0, 0)
      ..lineTo(size.width, 0)
      ..lineTo(size.width, 215)
      ..quadraticBezierTo(size.width / 2, 292, 0, 215)
      ..close();
    canvas.drawPath(top, white);
    canvas.drawRect(const Rect.fromLTWH(0, 0, 38, 78), brownPaint);
    canvas.drawCircle(Offset(size.width + 8, -5), 49, brownPaint);
    canvas.drawCircle(Offset(size.width + 8, -5), 28, white);
    canvas.drawCircle(Offset(-2, size.height - 20), 44, white);
    canvas.drawCircle(Offset(-2, size.height - 20), 25, brownPaint);
    canvas.drawRRect(
        RRect.fromRectAndRadius(
            Rect.fromLTWH(size.width - 42, size.height - 67, 46, 71),
            const Radius.circular(8)),
        white);
  }

  @override
  bool shouldRepaint(_BackendIdCardPainter oldDelegate) =>
      oldDelegate.brown != brown;
}

class _IdCardLine extends StatelessWidget {
  final String label;
  final String value;
  const _IdCardLine(this.label, this.value);
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: [
            Expanded(
              child: Text(label,
                  style: const TextStyle(color: Colors.white60, fontSize: 10)),
            ),
            Expanded(
                child: Text(value,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        color: Colors.white,
                        fontSize: 11,
                        fontWeight: FontWeight.w800))),
          ],
        ),
      );
}

class EnrollmentCertificatePage extends StatelessWidget {
  final StudentAccount student;
  const EnrollmentCertificatePage({super.key, required this.student});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: const _InnerHeader(title: 'Enrollment Certificate'),
        body: ListView(
          padding: const EdgeInsets.all(22),
          children: [
            Container(
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(
                color: const Color(0xfffffcf7),
                border: Border.all(color: const Color(0xff6b3f1f), width: 3),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(
                children: [
                  Image.asset('assets/grandview-logo.jpg',
                      width: 72, height: 72),
                  const SizedBox(height: 12),
                  const Text('GRANDVIEW SCHOOL',
                      style: TextStyle(
                          color: Color(0xff6b3f1f),
                          fontSize: 18,
                          fontWeight: FontWeight.w900)),
                  const SizedBox(height: 20),
                  const Text('CERTIFICATE OF ENROLLMENT',
                      style:
                          TextStyle(fontSize: 15, fontWeight: FontWeight.w900)),
                  const SizedBox(height: 18),
                  Text(
                    'This certifies that ${student.name}, student ID ${student.studentId}, is actively enrolled in ${student.grade} for academic year 2026–2027.',
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 12, height: 1.7),
                  ),
                  const SizedBox(height: 28),
                  const Divider(),
                  const Text('School Administration',
                      style:
                          TextStyle(fontSize: 10, fontWeight: FontWeight.w700)),
                ],
              ),
            ),
          ],
        ),
      );
}

class StudentAccount {
  final String name;
  final String studentId;
  final String grade;
  final String initials;
  const StudentAccount(this.name, this.studentId, this.grade, this.initials);
}

class _StudentScope extends InheritedWidget {
  final StudentAccount student;
  const _StudentScope({required this.student, required super.child});

  static StudentAccount of(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<_StudentScope>()!.student;

  @override
  bool updateShouldNotify(_StudentScope oldWidget) =>
      oldWidget.student != student;
}

class StudentTool {
  final String label;
  final IconData icon;
  final Color background;
  final Color foreground;
  final int atlasIndex;
  const StudentTool(
      this.label, this.icon, this.background, this.foreground, this.atlasIndex);
}

class _ToolButton extends StatelessWidget {
  final StudentTool tool;
  final VoidCallback onTap;
  const _ToolButton({required this.tool, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final state = context.findAncestorStateOfType<_StudentHomePageState>();
    final style =
        state?.appStyle ?? studentThemeStyles[StudentTheme.angkorWat]!;
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(18),
        child: Container(
          key: ValueKey('tool-card-${tool.label}'),
          width: double.infinity,
          height: double.infinity,
          padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 10),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [style.secondary, style.card, style.background],
            ),
            borderRadius: BorderRadius.circular(18),
            border: Border.all(
                color: style.accent.withValues(alpha: .34), width: 1.1),
            boxShadow: const [
              BoxShadow(
                color: Color(0x45241724),
                blurRadius: 9,
                offset: Offset(0, 4),
              ),
              BoxShadow(
                color: Color(0x306f526e),
                blurRadius: 2,
                offset: Offset(-1, -1),
              ),
            ],
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              _SchoolLineIcon(kind: tool.atlasIndex, size: 39),
              const SizedBox(height: 7),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 5),
                child: Text(
                  tool.label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: Color(0xfff9f5f8),
                    fontSize: 10.5,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _GeneratedAtlasIcon extends StatelessWidget {
  final String asset;
  final int index;
  final int columns;
  final int rows;
  final double size;
  final Color? color;
  const _GeneratedAtlasIcon({
    required this.asset,
    required this.index,
    required this.columns,
    required this.rows,
    required this.size,
    this.color,
  });

  @override
  Widget build(BuildContext context) {
    final column = index % columns;
    final row = index ~/ columns;
    final x = columns == 1 ? 0.0 : -1 + (2 * column / (columns - 1));
    final y = rows == 1 ? 0.0 : -1 + (2 * row / (rows - 1));
    return ClipRect(
      child: SizedBox.square(
        dimension: size,
        child: OverflowBox(
          alignment: Alignment(x, y),
          minWidth: size * columns,
          maxWidth: size * columns,
          minHeight: size * rows,
          maxHeight: size * rows,
          child: Image.asset(
            asset,
            width: size * columns,
            height: size * rows,
            fit: BoxFit.fill,
            color: color,
            colorBlendMode: color == null ? null : BlendMode.srcIn,
            filterQuality: FilterQuality.high,
          ),
        ),
      ),
    );
  }
}

class _SchoolLineIcon extends StatelessWidget {
  final int kind;
  final double size;
  const _SchoolLineIcon({required this.kind, this.size = 38});

  @override
  Widget build(BuildContext context) => CustomPaint(
        size: Size.square(size),
        painter: _SchoolLineIconPainter(kind),
      );
}

class _SchoolLineIconPainter extends CustomPainter {
  final int kind;
  const _SchoolLineIconPainter(this.kind);

  @override
  void paint(Canvas canvas, Size size) {
    final scale = size.width / 40;
    canvas.scale(scale);
    final paint = Paint()
      ..color = const Color(0xfffbf8fa)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.25
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    RRect rr(double l, double t, double r, double b, double radius) =>
        RRect.fromRectAndRadius(
            Rect.fromLTRB(l, t, r, b), Radius.circular(radius));
    void line(double x1, double y1, double x2, double y2) =>
        canvas.drawLine(Offset(x1, y1), Offset(x2, y2), paint);

    switch (kind) {
      case 0: // wallet
        canvas.drawRRect(rr(7, 11, 31, 29, 2.5), paint);
        line(10, 11, 27, 7);
        canvas.drawRRect(rr(23, 16, 34, 24, 2), paint);
        canvas.drawCircle(const Offset(27, 20), 1, paint);
        break;
      case 1: // profile scanner
        line(7, 13, 7, 8);
        line(7, 8, 12, 8);
        line(28, 8, 33, 8);
        line(33, 8, 33, 13);
        line(7, 27, 7, 32);
        line(7, 32, 12, 32);
        line(28, 32, 33, 32);
        line(33, 32, 33, 27);
        canvas.drawCircle(const Offset(20, 16), 4, paint);
        canvas.drawPath(
          Path()..addArc(const Rect.fromLTRB(13, 20, 27, 31), 3.35, 2.75),
          paint,
        );
        break;
      case 2: // homework clipboard
        canvas.drawRRect(rr(9, 9, 31, 33, 2.5), paint);
        canvas.drawRRect(rr(15, 6, 25, 12, 2), paint);
        line(14, 17, 26, 17);
        line(14, 22, 26, 22);
        line(14, 27, 23, 27);
        break;
      case 3: // attendance calendar
        canvas.drawRRect(rr(8, 10, 32, 32, 2.5), paint);
        line(8, 16, 32, 16);
        line(14, 7, 14, 12);
        line(26, 7, 26, 12);
        for (final y in [21.0, 27.0]) {
          for (final x in [14.0, 20.0, 26.0]) {
            canvas.drawCircle(Offset(x, y), .7, paint);
          }
        }
        break;
      case 4: // grade medal
        canvas.drawCircle(const Offset(20, 17), 9, paint);
        canvas.drawPath(
          Path()
            ..moveTo(14, 24)
            ..lineTo(13, 33)
            ..lineTo(20, 29)
            ..lineTo(27, 33)
            ..lineTo(26, 24),
          paint,
        );
        canvas.drawPath(
          Path()
            ..moveTo(20, 12)
            ..lineTo(21.7, 15.6)
            ..lineTo(25.6, 16.1)
            ..lineTo(22.8, 18.8)
            ..lineTo(23.5, 22.5)
            ..lineTo(20, 20.7)
            ..lineTo(16.5, 22.5)
            ..lineTo(17.2, 18.8)
            ..lineTo(14.4, 16.1)
            ..lineTo(18.3, 15.6)
            ..close(),
          paint,
        );
        break;
      case 5: // timetable clock
        canvas.drawCircle(const Offset(20, 21), 11, paint);
        line(20, 21, 20, 14);
        line(20, 21, 26, 24);
        line(11, 8, 7, 12);
        line(29, 8, 33, 12);
        line(14, 33, 12, 36);
        line(26, 33, 28, 36);
        break;
      case 6: // progress chart
        line(8, 31, 8, 9);
        line(8, 31, 33, 31);
        canvas.drawPath(
          Path()
            ..moveTo(12, 25)
            ..lineTo(17, 19)
            ..lineTo(22, 23)
            ..lineTo(31, 12),
          paint,
        );
        line(25, 12, 31, 12);
        line(31, 12, 31, 18);
        break;
      case 7: // gallery
        canvas.drawRRect(rr(10, 8, 33, 29, 2), paint);
        line(7, 12, 7, 33);
        line(7, 33, 29, 33);
        canvas.drawCircle(const Offset(27, 14), 2, paint);
        canvas.drawPath(
          Path()
            ..moveTo(13, 25)
            ..lineTo(18, 19)
            ..lineTo(22, 23)
            ..lineTo(26, 19)
            ..lineTo(31, 25),
          paint,
        );
        break;
      case 8: // classroom
        canvas.drawRRect(rr(7, 8, 33, 30, 2.5), paint);
        canvas.drawCircle(const Offset(20, 16), 4, paint);
        canvas.drawPath(
          Path()..addArc(const Rect.fromLTRB(13, 20, 27, 31), 3.35, 2.75),
          paint,
        );
        line(12, 33, 28, 33);
        break;
    }
  }

  @override
  bool shouldRepaint(covariant _SchoolLineIconPainter oldDelegate) =>
      oldDelegate.kind != kind;
}

class _ProfileTile extends StatelessWidget {
  final String initials;
  final String name;
  final String subtitle;
  final Color color;
  final String? imageAsset;
  final VoidCallback onTap;

  const _ProfileTile({
    required this.initials,
    required this.name,
    required this.subtitle,
    required this.color,
    required this.onTap,
    this.imageAsset,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      shape: RoundedRectangleBorder(
        side: const BorderSide(color: Color(0xffe1e7e5)),
        borderRadius: BorderRadius.circular(12),
      ),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 6),
          child: Row(
            children: [
              Container(
                width: 31,
                height: 31,
                clipBehavior: Clip.antiAlias,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: color,
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white, width: 2),
                  boxShadow: const [
                    BoxShadow(color: Color(0x22000000), blurRadius: 4),
                  ],
                ),
                child: imageAsset == null
                    ? Text(
                        initials,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 9,
                          fontWeight: FontWeight.w900,
                        ),
                      )
                    : Image.asset(imageAsset!, fit: BoxFit.cover),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    Text(
                      subtitle,
                      style: const TextStyle(
                        fontSize: 8,
                        color: Color(0xff879492),
                      ),
                    ),
                  ],
                ),
              ),
              const Icon(
                Icons.chevron_right_rounded,
                size: 17,
                color: Color(0xffa1aeac),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MenuTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final Widget? trailing;
  final VoidCallback onTap;

  const _MenuTile({
    required this.icon,
    required this.label,
    required this.onTap,
    this.trailing,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        border: Border(bottom: BorderSide(color: Color(0xffeee8e3))),
      ),
      child: ListTile(
        dense: true,
        minLeadingWidth: 22,
        horizontalTitleGap: 7,
        contentPadding: const EdgeInsets.symmetric(horizontal: 3),
        leading: Icon(icon, color: const Color(0xff6b3f1f), size: 19),
        title: Text(
          label,
          style: const TextStyle(fontSize: 12, color: Color(0xff50615e)),
        ),
        trailing: trailing,
        onTap: onTap,
      ),
    );
  }
}

class _SchoolSeal extends StatelessWidget {
  const _SchoolSeal();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 112,
      height: 112,
      padding: const EdgeInsets.all(7),
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: Colors.white,
        border: Border.all(color: const Color(0xff6b3f1f), width: 3),
        boxShadow: const [
          BoxShadow(color: Color(0x246b3f1f), blurRadius: 10),
        ],
      ),
      child: ClipOval(
        child: Image.asset('assets/grandview-logo.jpg', fit: BoxFit.contain),
      ),
    );
  }
}

class _MiniSchoolMark extends StatelessWidget {
  const _MiniSchoolMark();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 29,
      height: 29,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: Colors.white,
        shape: BoxShape.circle,
        border: Border.all(color: const Color(0xff6b3f1f), width: 2),
      ),
      child: Image.asset('assets/grandview-logo.jpg', fit: BoxFit.contain),
    );
  }
}
