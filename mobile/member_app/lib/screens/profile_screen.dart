import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

import 'chat_screen.dart';
import 'notifications_screen.dart';
import 'payments_screen.dart';

/// The member's own account: their details, and the way into everything
/// that is not the door badge.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final _firstName = TextEditingController();
  final _lastName = TextEditingController();
  final _email = TextEditingController();
  final _height = TextEditingController();
  final _weight = TextEditingController();
  final _bloodType = TextEditingController();
  final _diseases = TextEditingController();
  final _allergies = TextEditingController();
  final _emergencyName = TextEditingController();
  final _emergencyPhone = TextEditingController();

  bool _loading = true;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final controller in [
      _firstName,
      _lastName,
      _email,
      _height,
      _weight,
      _bloodType,
      _diseases,
      _allergies,
      _emergencyName,
      _emergencyPhone,
    ]) {
      controller.dispose();
    }

    super.dispose();
  }

  Future<void> _load() async {
    try {
      final response = await SessionScope.of(context).api.get('/me/dashboard');
      final member = ((response as Map)['member'] as Map).cast<String, dynamic>();

      // The dashboard carries the short profile; the full record comes with
      // the fields the member is allowed to edit.
      _firstName.text = '${member['first_name'] ?? ''}';
      _lastName.text = '${member['last_name'] ?? ''}';
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _save() async {
    setState(() => _saving = true);

    try {
      await SessionScope.of(context).api.put('/me/profile', body: {
        'first_name': _firstName.text.trim(),
        'last_name': _lastName.text.trim(),
        if (_email.text.trim().isNotEmpty) 'email': _email.text.trim(),
        if (_height.text.trim().isNotEmpty) 'height': int.tryParse(_height.text.trim()),
        if (_weight.text.trim().isNotEmpty) 'weight': double.tryParse(_weight.text.trim()),
        if (_bloodType.text.trim().isNotEmpty) 'blood_type': _bloodType.text.trim(),
        'diseases': _diseases.text.trim(),
        'allergies': _allergies.text.trim(),
        'emergency_name': _emergencyName.text.trim(),
        'emergency_phone': _emergencyPhone.text.trim(),
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(TranslationsScope.of(context).t('general.saved', 'Saved.')),
          ),
        );
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(error.firstFieldError ?? error.message)),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final session = SessionScope.of(context);

    if (_loading) return const LoadingState();

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 110),
      children: [
        ScreenHeader(
          title: t.t('members.profile', 'Profile'),
          subtitle: session.club?.name,
        ),

        // The shortcuts first: this is the screen a member opens to get
        // somewhere, not usually to edit their height.
        Row(
          children: [
            Expanded(
              child: _Shortcut(
                icon: Icons.receipt_long_outlined,
                label: t.t('members.payments', 'Payments'),
                onTap: () => Navigator.of(context)
                    .push(MaterialPageRoute(builder: (_) => const PaymentsScreen())),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: _Shortcut(
                icon: Icons.forum_outlined,
                label: t.t('nav.chat', 'Chat'),
                onTap: () => Navigator.of(context)
                    .push(MaterialPageRoute(builder: (_) => const MemberChatScreen())),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        _Shortcut(
          icon: Icons.notifications_none,
          label: t.t('members.notifications', 'Notifications'),
          onTap: () => Navigator.of(context)
              .push(MaterialPageRoute(builder: (_) => const NotificationsScreen())),
        ),

        const SizedBox(height: 16),
        GlassSection(
          title: t.t('nav.language', 'Language'),
          child: Wrap(
            spacing: 8,
            children: [
              for (final entry in t.locales.entries)
                ChoiceChip(
                  selected: t.locale == entry.key,
                  label: Text('${(entry.value as Map)['native']}'),
                  selectedColor: AppTheme.brand,
                  backgroundColor: Colors.white.withValues(alpha: 0.06),
                  labelStyle: TextStyle(
                    color: t.locale == entry.key ? AppTheme.ink900 : Colors.white,
                  ),
                  onSelected: (_) async {
                    await t.load(entry.key);
                    await session.setLocale(entry.key);
                  },
                ),
            ],
          ),
        ),

        const SizedBox(height: 16),
        GlassSection(
          title: t.t('members.profile', 'My details'),
          subtitle: t.t('members.profile_hint', 'Keep this current — your coach uses it.'),
          child: Column(
            children: [
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _firstName,
                      decoration: InputDecoration(
                        labelText: t.t('members.first_name', 'First name'),
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextField(
                      controller: _lastName,
                      decoration: InputDecoration(
                        labelText: t.t('members.last_name', 'Last name'),
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                decoration: InputDecoration(labelText: t.t('auth.email', 'Email')),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _height,
                      keyboardType: TextInputType.number,
                      decoration:
                          InputDecoration(labelText: t.t('members.height', 'Height (cm)')),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextField(
                      controller: _weight,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      decoration:
                          InputDecoration(labelText: t.t('members.weight', 'Weight (kg)')),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _bloodType,
                decoration:
                    InputDecoration(labelText: t.t('members.blood_type', 'Blood type')),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _diseases,
                maxLines: 2,
                decoration: InputDecoration(
                  labelText: t.t('members.diseases', 'Medical conditions'),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _allergies,
                maxLines: 2,
                decoration:
                    InputDecoration(labelText: t.t('members.allergies', 'Allergies')),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _emergencyName,
                decoration: InputDecoration(
                  labelText: t.t('members.emergency_name', 'Emergency contact'),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _emergencyPhone,
                keyboardType: TextInputType.phone,
                decoration: InputDecoration(
                  labelText: t.t('members.emergency_phone', 'Emergency phone'),
                ),
              ),
              const SizedBox(height: 18),
              BrandButton(
                label: t.t('general.save', 'Save'),
                loading: _saving,
                onPressed: _save,
              ),
            ],
          ),
        ),

        const SizedBox(height: 16),
        OutlinedButton.icon(
          onPressed: session.signOut,
          icon: const Icon(Icons.logout, size: 18),
          label: Text(t.t('nav.sign_out', 'Sign out')),
        ),
      ],
    );
  }
}

class _Shortcut extends StatelessWidget {
  const _Shortcut({required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 18),
      child: Row(
        children: [
          Icon(icon, color: AppTheme.brand, size: 22),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              label,
              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
            ),
          ),
          const Icon(Icons.chevron_right, color: AppTheme.ink400, size: 20),
        ],
      ),
    );
  }
}
