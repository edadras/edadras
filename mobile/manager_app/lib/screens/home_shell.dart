import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

import 'ai_screen.dart';
import 'dashboard_screen.dart';
import 'members_screen.dart';
import 'more_screen.dart';
import 'scanner_screen.dart';

/// The manager app's tab bar. Only four things live here — the ones a phone
/// is used for all day — and everything else sits behind "More". Tabs the
/// signed-in role cannot open are never built, so a receptionist opens
/// straight onto the scanner.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    final t = TranslationsScope.of(context);

    final tabs = <_Tab>[
      if (session.can('dashboard.view'))
        _Tab(
          label: t.t('nav.dashboard', 'Dashboard'),
          icon: Icons.space_dashboard_outlined,
          selectedIcon: Icons.space_dashboard,
          screen: const DashboardScreen(),
        ),
      if (session.can('attendance.checkin'))
        _Tab(
          label: t.t('nav.check_in', 'Check-in'),
          icon: Icons.qr_code_scanner_outlined,
          selectedIcon: Icons.qr_code_scanner,
          screen: const ScannerScreen(),
        ),
      if (session.can('members.view'))
        _Tab(
          label: t.t('nav.members', 'Members'),
          icon: Icons.group_outlined,
          selectedIcon: Icons.group,
          screen: const MembersScreen(),
        ),
      if (session.can('ai.view'))
        _Tab(
          label: t.t('nav.ai', 'AI'),
          icon: Icons.auto_awesome_outlined,
          selectedIcon: Icons.auto_awesome,
          screen: const AiScreen(),
        ),
      _Tab(
        label: t.t('nav.more', 'More'),
        icon: Icons.grid_view_outlined,
        selectedIcon: Icons.grid_view,
        screen: const MoreScreen(),
      ),
    ];

    final index = _index.clamp(0, tabs.length - 1);

    return Scaffold(
      extendBody: true,
      body: GradientBackdrop(
        child: SafeArea(bottom: false, child: tabs[index].screen),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: index,
        onDestinationSelected: (value) => setState(() => _index = value),
        destinations: [
          for (final tab in tabs)
            NavigationDestination(
              icon: Icon(tab.icon),
              selectedIcon: Icon(tab.selectedIcon, color: AppTheme.brand),
              label: tab.label,
            ),
        ],
      ),
    );
  }
}

class _Tab {
  const _Tab({
    required this.label,
    required this.icon,
    required this.selectedIcon,
    required this.screen,
  });

  final String label;
  final IconData icon;
  final IconData selectedIcon;
  final Widget screen;
}
