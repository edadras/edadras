import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

import 'membership_screen.dart';
import 'programs_screen.dart';
import 'schedule_screen.dart';

/// Four tabs: the card the member shows at the door, the timetable they
/// book from, their programs, and their history.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    final screens = [
      const MembershipScreen(),
      const ScheduleScreen(),
      const ProgramsScreen(),
    ];

    return Scaffold(
      extendBody: true,
      body: GradientBackdrop(
        child: SafeArea(bottom: false, child: screens[_index]),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (value) => setState(() => _index = value),
        destinations: [
          NavigationDestination(
            icon: const Icon(Icons.qr_code_2_outlined),
            selectedIcon: const Icon(Icons.qr_code_2, color: AppTheme.brand),
            label: t.t('members.qr', 'My card'),
          ),
          NavigationDestination(
            icon: const Icon(Icons.event_outlined),
            selectedIcon: const Icon(Icons.event, color: AppTheme.brand),
            label: t.t('classes.timetable', 'Schedule'),
          ),
          NavigationDestination(
            icon: const Icon(Icons.fitness_center_outlined),
            selectedIcon: const Icon(Icons.fitness_center, color: AppTheme.brand),
            label: t.t('members.programs', 'Programs'),
          ),
        ],
      ),
    );
  }
}
