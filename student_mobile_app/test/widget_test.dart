import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:parence_student/main.dart';

void main() {
  testWidgets('student dashboard renders and tools respond', (tester) async {
    tester.view.physicalSize = const Size(430, 932);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(const StudentApp());
    await tester.pump();

    expect(find.text('Sombat Sok'), findsOneWidget);
    expect(find.text('What do you need?'), findsOneWidget);
    expect(find.text('My Wallet'), findsOneWidget);
    expect(
      find.image(const AssetImage('assets/grandview-logo.jpg')),
      findsOneWidget,
    );
    expect(
      find.image(const AssetImage('assets/welcome-back.png')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('persistent-scan-button')));
    await tester.pumpAndSettle();
    expect(find.text('Scan the student QR code'), findsOneWidget);
    expect(find.byIcon(Icons.flash_off_rounded), findsOneWidget);
    await tester.tap(find.byIcon(Icons.flash_off_rounded));
    await tester.pump();
    expect(find.byIcon(Icons.flash_on_rounded), findsOneWidget);
    await tester.tap(find.text('Home'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('My Profile'));
    await tester.pumpAndSettle();
    expect(find.text('Student Information'), findsOneWidget);
    expect(find.text('GVS-023'), findsOneWidget);
    expect(find.text('Academic year'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('My Wallet'));
    await tester.pumpAndSettle();
    expect(find.text('School Fees'), findsOneWidget);
    expect(find.text('Total school fees: 350.00\$'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Homework'));
    await tester.pumpAndSettle();
    expect(find.text('Homework'), findsOneWidget);
    expect(find.text('Mathematics • Unit 1'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Attendance'));
    await tester.pumpAndSettle();
    expect(find.text('Attendance'), findsOneWidget);
    expect(find.text('Request leave'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('My Grades'));
    await tester.pumpAndSettle();
    expect(find.text('Grade Reports'), findsOneWidget);
    await tester.tap(find.text('Monthly').first);
    await tester.pumpAndSettle();
    expect(find.text('Monthly Report'), findsOneWidget);
    expect(find.text('Total Average'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Semester').first);
    await tester.pumpAndSettle();
    expect(find.text('First Quarter'), findsOneWidget);
    expect(find.text('Second Quarter'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Annually').first);
    await tester.pumpAndSettle();
    expect(find.text('Semester 1'), findsOneWidget);
    expect(find.text('Semester 2'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Monthly').last);
    await tester.pumpAndSettle();
    expect(find.text('Monthly in 2026–2027'), findsOneWidget);
    expect(find.text('Earth Science'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Semester').last);
    await tester.pumpAndSettle();
    expect(find.text('Semester 1 in 2026–2027'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Annually').last);
    await tester.pumpAndSettle();
    expect(find.text('Year 2026–2027'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Timetable'));
    await tester.pumpAndSettle();
    expect(find.text('Class Timetable'), findsOneWidget);
    expect(find.text('Mathematics'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Progress'));
    await tester.pumpAndSettle();
    expect(find.text('Report Student'), findsOneWidget);
    expect(find.text('Student Evaluation'), findsOneWidget);
    expect(find.text('Submit Feedback'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('My Classes'));
    await tester.pumpAndSettle();
    expect(find.text('Grandview Grade 9A'), findsOneWidget);
    expect(find.text('Math Worksheet Chapter 4'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.byIcon(Icons.menu_rounded).first);
    await tester.pumpAndSettle();
    expect(find.text('GRANDVIEW SCHOOL'), findsWidgets);
    expect(find.text('Switch Student'), findsOneWidget);
    expect(find.text('4 children linked'), findsOneWidget);
    expect(find.text('Privacy & security'), findsOneWidget);
    expect(find.text('Notifications'), findsOneWidget);
    expect(find.text('App Version: V1.0.0'), findsOneWidget);

    await tester.tap(find.text('Switch Student'));
    await tester.pumpAndSettle();
    expect(find.text('Switch student account'), findsOneWidget);
    expect(find.text('Dara Sok'), findsOneWidget);
    expect(find.text('Lina Sok'), findsOneWidget);
    expect(find.text('Nita Sok'), findsOneWidget);
    await tester.tap(find.text('Dara Sok'));
    await tester.pumpAndSettle();
    expect(find.text('Dara Sok'), findsOneWidget);
    await tester.tap(find.text('My Profile'));
    await tester.pumpAndSettle();
    expect(find.text('GVS-107'), findsOneWidget);
    expect(find.text('Grade 6B'), findsWidgets);
    await tester.tap(find.text('Student ID Card'));
    await tester.pumpAndSettle();
    expect(find.textContaining('STUDENT ID CARD'), findsOneWidget);
    expect(find.text('GVS-107'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Certificate'));
    await tester.pumpAndSettle();
    expect(find.text('CERTIFICATE OF ENROLLMENT'), findsOneWidget);
    expect(find.textContaining('Dara Sok'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(Icons.menu_rounded).first);
    await tester.pumpAndSettle();

    await tester.tap(find.text('Language'));
    await tester.pumpAndSettle();
    expect(find.text('ខ្មែរ'), findsOneWidget);
    expect(find.text('中文'), findsOneWidget);
    await tester.tap(find.text('ខ្មែរ'));
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(Icons.menu_rounded).first);
    await tester.pumpAndSettle();
    expect(find.text('ភាសា'), findsOneWidget);
    await tester.tap(find.text('ភាសា'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('English'));
    await tester.pumpAndSettle();

    await tester.tap(find.byIcon(Icons.menu_rounded).first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Theme'));
    await tester.pumpAndSettle();
    expect(find.text('Follow System'), findsOneWidget);
    await tester.tap(find.text('Dark'));
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(Icons.menu_rounded).first);
    await tester.pumpAndSettle();
    expect(find.text('Dark'), findsOneWidget);
    await tester.tap(find.text('Theme'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Light'));
    await tester.pumpAndSettle();

    await tester.tap(find.byIcon(Icons.menu_rounded).first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Download App'));
    await tester.pumpAndSettle();
    expect(find.text('Download Grandview App'), findsOneWidget);
    expect(find.text('APK build pending'), findsOneWidget);
    expect(find.text('Signed IPA pending'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Gallery'));
    await tester.pumpAndSettle();
    expect(find.text('School News'), findsOneWidget);
    expect(find.text('Latest school activities and announcements'),
        findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Payments'));
    await tester.pumpAndSettle();
    expect(find.text('Payment Records'), findsOneWidget);
    expect(find.text('Invoice #08'), findsOneWidget);
    await tester.tap(find.text('Invoice #08'));
    await tester.pumpAndSettle();
    expect(find.text('Payment Detail'), findsOneWidget);
    expect(find.text('Dara Sok • GVS-107'), findsOneWidget);
    await tester.tap(find.text('View Payment Certificate'));
    await tester.pumpAndSettle();
    expect(find.text('Payment Certificate'), findsOneWidget);
    expect(find.text('Dara Sok'), findsOneWidget);
    expect(find.text('GVS-107'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();
    expect(find.text('Send Invoice Request'), findsOneWidget);
    await tester.tap(find.text('Send Invoice Request'));
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.textContaining('queued for backend'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(Icons.chevron_left_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Contact'));
    await tester.pumpAndSettle();
    expect(find.text('Contact Grandview'), findsOneWidget);
    expect(find.text('info@grandviewschool.edu.kh'), findsOneWidget);
  });
}
