import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

import 'chat_screen.dart';
import 'classes_screen.dart';
import 'coaches_screen.dart';
import 'finance_screen.dart';
import 'reports_screen.dart';
import 'settings_screen.dart';
import 'shop_screen.dart';

/// The rest of the club, one tap away. Keeping these off the bottom bar
/// leaves it to the four things a phone is actually used for all day.
class MoreScreen extends StatelessWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final session = SessionScope.of(context);

    final entries = <_Entry>[
      _Entry(
        label: t.t('nav.classes', 'Classes'),
        icon: Icons.event_available_outlined,
        permission: 'classes.view',
        builder: () => const ClassesScreen(),
      ),
      _Entry(
        label: t.t('nav.coaches', 'Coaches'),
        icon: Icons.sports_outlined,
        permission: 'coaches.view',
        builder: () => const CoachesScreen(),
      ),
      _Entry(
        label: t.t('nav.finance', 'Finance'),
        icon: Icons.account_balance_wallet_outlined,
        permission: 'finance.view',
        builder: () => const FinanceScreen(),
      ),
      _Entry(
        label: t.t('nav.shop', 'Shop'),
        icon: Icons.storefront_outlined,
        permission: 'shop.view',
        builder: () => const ShopScreen(),
      ),
      _Entry(
        label: t.t('nav.reports', 'Reports'),
        icon: Icons.insights_outlined,
        permission: 'reports.view',
        builder: () => const ReportsScreen(),
      ),
      _Entry(
        label: t.t('nav.chat', 'Chat'),
        icon: Icons.forum_outlined,
        permission: 'chat.view',
        builder: () => const ConversationsScreen(),
      ),
      _Entry(
        label: t.t('nav.settings', 'Settings'),
        icon: Icons.settings_outlined,
        permission: 'settings.view',
        builder: () => const SettingsScreen(),
      ),
    ].where((entry) => session.can(entry.permission)).toList();

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 110),
      children: [
        ScreenHeader(
          title: t.t('nav.more', 'More'),
          subtitle: session.club?.name,
        ),
        GridView.count(
          crossAxisCount: MediaQuery.sizeOf(context).width > 620 ? 3 : 2,
          mainAxisSpacing: 12,
          crossAxisSpacing: 12,
          childAspectRatio: 1.5,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          children: [
            for (final entry in entries)
              GlassCard(
                onTap: () => Navigator.of(context)
                    .push(MaterialPageRoute(builder: (_) => entry.builder())),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(entry.icon, color: AppTheme.brand, size: 26),
                    const SizedBox(height: 12),
                    Text(
                      entry.label,
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
        const SizedBox(height: 20),
        OutlinedButton.icon(
          onPressed: session.signOut,
          icon: const Icon(Icons.logout, size: 18),
          label: Text(t.t('nav.sign_out', 'Sign out')),
        ),
      ],
    );
  }
}

class _Entry {
  const _Entry({
    required this.label,
    required this.icon,
    required this.permission,
    required this.builder,
  });

  final String label;
  final IconData icon;
  final String permission;
  final Widget Function() builder;
}
