import 'package:flutter/material.dart';

class StudentLoginPage extends StatefulWidget {
  final Future<void> Function(String studentId, String password) onLogin;
  final String? initialError;
  const StudentLoginPage({super.key, required this.onLogin, this.initialError});

  @override
  State<StudentLoginPage> createState() => _StudentLoginPageState();
}

class _StudentLoginPageState extends State<StudentLoginPage> {
  final _formKey = GlobalKey<FormState>();
  final _studentId = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  bool _hidePassword = true;
  String? _error;

  @override
  void dispose() {
    _studentId.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() { _busy = true; _error = null; });
    try {
      await widget.onLogin(_studentId.text, _password.text);
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Form(
                      key: _formKey,
                      child: Column(mainAxisSize: MainAxisSize.min, children: [
                        Image.asset('assets/grandview-logo.jpg', height: 92),
                        const SizedBox(height: 18),
                        Text('Student Login', style: Theme.of(context).textTheme.headlineSmall),
                        const SizedBox(height: 20),
                        TextFormField(
                          controller: _studentId,
                          textCapitalization: TextCapitalization.characters,
                          decoration: const InputDecoration(labelText: 'Student ID', prefixIcon: Icon(Icons.badge_outlined), border: OutlineInputBorder()),
                          validator: (value) => value == null || value.trim().isEmpty ? 'Enter your Student ID' : null,
                        ),
                        const SizedBox(height: 14),
                        TextFormField(
                          controller: _password,
                          obscureText: _hidePassword,
                          onFieldSubmitted: (_) => _submit(),
                          decoration: InputDecoration(labelText: 'Password', prefixIcon: const Icon(Icons.lock_outline), border: const OutlineInputBorder(), suffixIcon: IconButton(onPressed: () => setState(() => _hidePassword = !_hidePassword), icon: Icon(_hidePassword ? Icons.visibility_outlined : Icons.visibility_off_outlined))),
                          validator: (value) => value == null || value.isEmpty ? 'Enter your password' : null,
                        ),
                        if ((_error ?? widget.initialError) != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error ?? widget.initialError!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
                        const SizedBox(height: 18),
                        SizedBox(width: double.infinity, child: FilledButton(onPressed: _busy ? null : _submit, child: Padding(padding: const EdgeInsets.all(13), child: Text(_busy ? 'Signing in…' : 'Sign in')))),
                      ]),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      );
}
