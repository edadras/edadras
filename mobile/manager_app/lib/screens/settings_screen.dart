import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

/// The club profile a manager can fix from their phone, plus the language
/// switch that applies to the whole app.
class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _address = TextEditingController();
  final _rules = TextEditingController();

  bool _loading = true;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _address.dispose();
    _rules.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final response = await SessionScope.of(context).api.get('/club');
      final json = (response as Map).cast<String, dynamic>();

      _name.text = '${json['name'] ?? ''}';
      _phone.text = '${json['phone'] ?? ''}';
      _address.text = '${json['address'] ?? ''}';
      _rules.text = '${json['rules'] ?? ''}';
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
      await SessionScope.of(context).api.put('/club', body: {
        'name': _name.text.trim(),
        'phone': _phone.text.trim(),
        'address': _address.text.trim(),
        'rules': _rules.text.trim(),
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
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);
    final session = SessionScope.of(context);
    final canEdit = session.can('settings.update');

    return DetailScaffold(
      title: t.t('nav.settings', 'Settings'),
      child: _loading
          ? const LoadingState()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
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
                  title: t.t('settings.club', 'Club profile'),
                  child: Column(
                    children: [
                      TextField(
                        controller: _name,
                        enabled: canEdit,
                        decoration:
                            InputDecoration(labelText: t.t('auth.club_name', 'Club name')),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: _phone,
                        enabled: canEdit,
                        keyboardType: TextInputType.phone,
                        decoration: InputDecoration(labelText: t.t('auth.phone', 'Phone')),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: _address,
                        enabled: canEdit,
                        decoration: InputDecoration(labelText: t.t('auth.address', 'Address')),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: _rules,
                        enabled: canEdit,
                        maxLines: 4,
                        decoration:
                            InputDecoration(labelText: t.t('settings.rules', 'Club rules')),
                      ),
                      if (canEdit) ...[
                        const SizedBox(height: 18),
                        BrandButton(
                          label: t.t('general.save', 'Save'),
                          loading: _saving,
                          onPressed: _save,
                        ),
                      ],
                    ],
                  ),
                ),
              ],
            ),
    );
  }
}
