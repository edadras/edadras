import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

import 'screens/home_shell.dart';
import 'screens/login_screen.dart';

void main() {
  // Each club ships its own build, so the slug can be baked in and the
  // member only ever types their phone number and password.
  const apiUrl = String.fromEnvironment(
    'API_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );
  const clubSlug = String.fromEnvironment('CLUB_SLUG');

  runApp(MemberApp(api: ApiClient(baseUrl: apiUrl), clubSlug: clubSlug));
}

class MemberApp extends StatefulWidget {
  const MemberApp({super.key, required this.api, this.clubSlug = ''});

  final ApiClient api;
  final String clubSlug;

  @override
  State<MemberApp> createState() => _MemberAppState();
}

class _MemberAppState extends State<MemberApp> {
  late final SessionController _session = SessionController(widget.api);
  late final Translations _translations = Translations(widget.api);

  @override
  void initState() {
    super.initState();
    _boot();
  }

  Future<void> _boot() async {
    await _session.restore();

    if (widget.clubSlug.isNotEmpty && widget.api.tenant == null) {
      widget.api.configure(
        token: widget.api.token,
        tenant: widget.clubSlug,
        locale: widget.api.locale,
      );
    }

    try {
      await _translations.loadLocales();
      await _translations.load(widget.api.locale);
    } catch (_) {
      // Offline: the interface falls back to its keys until the API is back.
    }

    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    return SessionScope(
      session: _session,
      child: TranslationsScope(
        translations: _translations,
        child: AnimatedBuilder(
          animation: Listenable.merge([_session, _translations]),
          builder: (context, _) {
            return MaterialApp(
              title: 'GymFlow AI',
              debugShowCheckedModeBanner: false,
              theme: AppTheme.build(
                accent: AppTheme.parseBrandColor(_session.club?.brandColor),
              ),
              builder: (context, child) => Directionality(
                textDirection: _translations.direction,
                child: child ?? const SizedBox.shrink(),
              ),
              home: _session.isRestoring
                  ? const Scaffold(
                      body: GradientBackdrop(
                        child: Center(
                          child: CircularProgressIndicator(color: AppTheme.brand),
                        ),
                      ),
                    )
                  : _session.isAuthenticated
                      ? const HomeShell()
                      : LoginScreen(clubSlug: widget.clubSlug),
            );
          },
        ),
      ),
    );
  }
}
