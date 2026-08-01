import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

/// Members sign in with the phone number the club has on file.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, this.clubSlug = ''});

  final String clubSlug;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  late final TextEditingController _tenant =
      TextEditingController(text: widget.clubSlug);

  bool _loading = false;
  String? _error;

  @override
  void dispose() {
    _phone.dispose();
    _password.dispose();
    _tenant.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      await SessionScope.of(context).signInAsMember(
        tenant: _tenant.text.trim(),
        phone: _phone.text.trim(),
        password: _password.text,
      );
    } on ApiException catch (error) {
      setState(() => _error = error.firstFieldError ?? error.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return Scaffold(
      body: GradientBackdrop(
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: GlassCard(
                  padding: const EdgeInsets.all(24),
                  child: Form(
                    key: _formKey,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Center(
                          child: Container(
                            width: 62,
                            height: 62,
                            alignment: Alignment.center,
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(20),
                              gradient: const LinearGradient(
                                colors: [AppTheme.brandSoft, AppTheme.brandDeep],
                              ),
                            ),
                            child: const Icon(
                              Icons.fitness_center,
                              size: 30,
                              color: AppTheme.ink900,
                            ),
                          ),
                        ),
                        const SizedBox(height: 20),
                        Text(
                          t.t('auth.sign_in', 'Sign in'),
                          textAlign: TextAlign.center,
                          style: Theme.of(context).textTheme.headlineSmall,
                        ),
                        const SizedBox(height: 24),

                        // Hidden when the build already knows its club.
                        if (widget.clubSlug.isEmpty) ...[
                          TextFormField(
                            controller: _tenant,
                            decoration:
                                InputDecoration(labelText: t.t('auth.club', 'Club')),
                            validator: (value) =>
                                (value == null || value.trim().isEmpty) ? '—' : null,
                          ),
                          const SizedBox(height: 14),
                        ],

                        TextFormField(
                          controller: _phone,
                          keyboardType: TextInputType.phone,
                          decoration:
                              InputDecoration(labelText: t.t('auth.phone', 'Phone')),
                          validator: (value) =>
                              (value == null || value.trim().length < 6) ? '—' : null,
                        ),
                        const SizedBox(height: 14),
                        TextFormField(
                          controller: _password,
                          obscureText: true,
                          decoration: InputDecoration(
                            labelText: t.t('auth.password_label', 'Password'),
                          ),
                          onFieldSubmitted: (_) => _submit(),
                          validator: (value) =>
                              (value == null || value.isEmpty) ? '—' : null,
                        ),

                        if (_error != null) ...[
                          const SizedBox(height: 14),
                          Container(
                            padding:
                                const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                            decoration: BoxDecoration(
                              color: AppTheme.danger.withValues(alpha: 0.15),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Text(
                              _error!,
                              style: const TextStyle(
                                color: Color(0xFFFDA4AF),
                                fontSize: 13,
                              ),
                            ),
                          ),
                        ],

                        const SizedBox(height: 22),
                        BrandButton(
                          label: t.t('auth.sign_in', 'Sign in'),
                          loading: _loading,
                          onPressed: _submit,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
