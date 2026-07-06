"use client";

import { useState, useEffect } from "react";
import { useRouter } from "next/navigation";
import { Lock, Mail, ArrowRight } from "lucide-react";
import FormInput from "@/components/forms/FormInput";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    // If already logged in, skip to dashboard
    if (localStorage.getItem("erp_user")) {
      router.push("/dashboard");
    }
  }, [router]);

  const handleLogin = (e: React.FormEvent) => {
    e.preventDefault();
    setError("");

    if (!email || !password) {
      setError("Please fill in all fields.");
      return;
    }

    setLoading(true);

    // Simple mock authentication
    setTimeout(() => {
      if (
        (email === "admin@nusaevo.com" && password === "password") ||
        (email === "admin" && password === "admin")
      ) {
        localStorage.setItem(
          "erp_user",
          JSON.stringify({
            name: "Administrator",
            email: "admin@nusaevo.com",
            role: "admin",
          })
        );
        router.push("/dashboard");
      } else {
        setError("Invalid email or password. Use: admin / admin");
        setLoading(false);
      }
    }, 800);
  };

  const prefillAdmin = () => {
    setEmail("admin@nusaevo.com");
    setPassword("password");
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-950 relative overflow-hidden px-4">
      {/* Background blobs for premium feeling */}
      <div className="absolute top-1/4 left-1/4 w-96 h-96 rounded-full bg-indigo-650/20 blur-3xl animate-pulse-subtle"></div>
      <div className="absolute bottom-1/4 right-1/4 w-96 h-96 rounded-full bg-violet-650/15 blur-3xl animate-pulse-subtle" style={{ animationDelay: "2s" }}></div>

      {/* Glassmorphic Login Card */}
      <div className="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/60 backdrop-blur-xl p-8 shadow-2xl z-10">
        <div className="text-center space-y-2 mb-8">
          <div className="inline-flex h-12 w-12 rounded-xl bg-indigo-600 items-center justify-center text-white font-extrabold text-2xl shadow-lg shadow-indigo-600/30">
            N
          </div>
          <h1 className="text-2xl font-bold tracking-tight text-white">
            Welcome to NusaEvo <span className="text-indigo-400">ERP</span>
          </h1>
          <p className="text-xs text-slate-400">
            Sign in to access your modules & manage operations.
          </p>
        </div>

        {error && (
          <div className="mb-4 p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs font-semibold text-center">
            {error}
          </div>
        )}

        <form onSubmit={handleLogin} className="space-y-5">
          <div className="space-y-1">
            <FormInput
              label="Email Address"
              name="email"
              type="text"
              placeholder="e.g. admin@nusaevo.com"
              value={email}
              onChange={setEmail}
              required
            />
          </div>

          <div className="space-y-1">
            <FormInput
              label="Password"
              name="password"
              type="password"
              placeholder="••••••••"
              value={password}
              onChange={setPassword}
              required
            />
          </div>

          <div className="flex items-center justify-between text-xs text-slate-450">
            <label className="flex items-center gap-1.5 cursor-pointer select-none">
              <input
                type="checkbox"
                className="rounded border-slate-700 bg-slate-850 text-indigo-600 focus:ring-indigo-600 focus:ring-offset-slate-900"
              />
              Remember me
            </label>
            <a href="#" className="text-indigo-400 hover:text-indigo-300 font-semibold transition-colors">
              Forgot password?
            </a>
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full flex items-center justify-center gap-2 rounded-xl bg-indigo-600 py-3 text-xs font-bold text-white shadow-lg shadow-indigo-600/20 hover:bg-indigo-500 active:scale-[0.98] transition-all disabled:opacity-50 disabled:pointer-events-none"
          >
            {loading ? (
              <div className="h-4.5 w-4.5 animate-spin rounded-full border-2 border-white border-t-transparent"></div>
            ) : (
              <>
                Sign In
                <ArrowRight className="h-4 w-4" />
              </>
            )}
          </button>
        </form>

        <div className="relative my-6 text-center">
          <div className="absolute inset-0 flex items-center">
            <div className="w-full border-t border-slate-800"></div>
          </div>
          <span className="relative bg-slate-900 px-3 text-[10px] uppercase font-bold tracking-wider text-slate-500">
            Demo Sandbox
          </span>
        </div>

        <button
          onClick={prefillAdmin}
          className="w-full rounded-xl border border-slate-800 bg-slate-950/40 py-2.5 text-xs font-semibold text-slate-350 hover:bg-slate-950 hover:text-white transition-all text-center"
        >
          Prefill Demo Admin Credentials
        </button>
      </div>
    </div>
  );
}
