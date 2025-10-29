// components/EditUserModal.jsx

import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Button } from '@/components/ui/button';
import { ImagePlus, X, ChevronDown } from 'lucide-react';
import { useInitials } from '@/hooks/use-initials';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import RolesMultiSelect from './RolesMultiSelect';

const EditUserModal = ({ open, editedUser, onClose, roles, status, trainings }) => {
    const getInitials = useInitials();
    const [errors, setErrors] = useState({});

    const [formData, setFormData] = useState({
        name: '',
        email: '',
        roles: [],
        status: '',
        formation_id: null,
        phone: '',
        cin: '',
        image: null,
    });

    // ✅ Load user data into form when modal opens or user changes
    useEffect(() => {
        if (editedUser) {
            // Normalize roles to an array of lowercase strings
            let rolesArray = [];
            if (Array.isArray(editedUser.role)) {
                rolesArray = editedUser.role;
            } else if (typeof editedUser.role === 'string' && editedUser.role.length > 0) {
                try {
                    const parsed = JSON.parse(editedUser.role);
                    if (Array.isArray(parsed)) rolesArray = parsed;
                    else rolesArray = editedUser.role.split(',').map((r) => r.trim()).filter(Boolean);
                } catch {
                    rolesArray = editedUser.role.split(',').map((r) => r.trim()).filter(Boolean);
                }
            }
            rolesArray = rolesArray.map(r => String(r).toLowerCase());
            setFormData({
                name: editedUser.name || '',
                email: editedUser.email || '',
                roles: rolesArray,
                status: editedUser.status || '',
                formation_id: editedUser.formation_id || null,
                phone: editedUser.phone || '',
                cin: editedUser.cin || '',
                image: editedUser.image || null,
            });
        }
    }, [editedUser]);

    const submitEdit = (e) => {
        e.preventDefault();

        if (!editedUser) return;

        const form = new FormData();

        form.append('_method', 'put');
        form.append('name', formData.name);
        form.append('email', formData.email);
        // send roles as array
        formData.roles.forEach((r) => form.append('roles[]', r));
        form.append('status', formData.status);
        form.append('phone', formData.phone);
        form.append('cin', formData.cin);
        form.append('formation_id', formData.formation_id || '');

        // ✅ Append image ONLY if it's a File
        if (formData.image instanceof File) {
            form.append('image', formData.image);
        }

        router.post(`/admin/users/update/${editedUser.id}`, form, {
            onSuccess: () => {
                setErrors({});
                onClose();
            },
            onError: (err) => {
                setErrors(err);
                // console.log('Validation errors:', err);
            },
        });
    };
    function resendLink(userId) {
        // alert(userId);
        router.post(`/admin/users/${userId}/resend-link`);
    }
    const resetPassword = (id) => {
        router.post(`/admin/users/${id}/reset-password`, {
            onSuccess: () => {
                alert('fine')
            },
            onError: () => {
                alert('error')
            }
        })
    }



    return (
        <Dialog open={open} onOpenChange={(val) => !val && onClose()}>
            <DialogContent className="sm:max-w-[720px] bg-light text-dark dark:bg-dark dark:text-light">
                <DialogHeader>
                    <DialogTitle>Modify user</DialogTitle>
                </DialogHeader>
                <form onSubmit={submitEdit} className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                    {/* Avatar */}
                    <div className="col-span-1 md:col-span-2 flex justify-center items-center gap-4 mb-4">
                        <div className="relative w-24 h-24">
                            <Avatar className="w-24 h-24 rounded-full overflow-hidden">
                                {formData.image && formData.image instanceof File ? (
                                    <AvatarImage src={URL.createObjectURL(formData.image)} alt="User Avatar" />
                                ) : (
                                    <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                        {getInitials(formData.name)}
                                    </AvatarFallback>
                                )}
                            </Avatar>

                            <label className="absolute bottom-0 right-0 flex items-center justify-center w-8 h-8 bg-alpha rounded-full cursor-pointer border-2 border-white hover:bg-alpha/80">
                                <ImagePlus size={18} className="text-white" />
                                <input
                                    type="file"
                                    accept="image/*"
                                    className="absolute inset-0 opacity-0 cursor-pointer"
                                    onChange={(e) =>
                                        setFormData({ ...formData, image: e.target.files?.[0] || null })
                                    }
                                />
                            </label>
                        </div>
                    </div>

                    {/* Fields */}
                    <div className="col-span-1">

                        {/* {console.log(editedUser)} */}
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={formData.name}
                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                        />
                    </div>
                    <div className="col-span-1">
                        <Label htmlFor="email">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            value={formData.email}
                            onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                        />
                    </div>
                    <div className="col-span-1">
                        <Label htmlFor="phone">Phone</Label>
                        <Input
                            id="phone"
                            value={formData.phone}
                            onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                        />
                    </div>
                    <div className="col-span-1">
                        <Label htmlFor="cin">CIN</Label>
                        <Input
                            id="cin"
                            value={formData.cin}
                            onChange={(e) => setFormData({ ...formData, cin: e.target.value })}
                        />
                    </div>
                    {/* Roles - multi-select dropdown with chips (like Add User) */}
                    <div className="col-span-1">
                        <Label>Status</Label>
                        <Select
                            value={formData.status}
                            onValueChange={(v) => setFormData({ ...formData, status: v })}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select status" />
                            </SelectTrigger>
                            <SelectContent>
                                {status.map((s, idx) => (
                                    <SelectItem key={idx} value={s}>
                                        {s}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="col-span-1">
                        <Label htmlFor="roles">Roles</Label>
                        <RolesMultiSelect roles={formData.roles} onChange={(newRoles) => setFormData({ ...formData, roles: newRoles })} />
                    </div>
                    <div className="col-span-1 md:col-span-2">
                        <Label>Training</Label>
                        <Select
                            value={formData.formation_id ? String(formData.formation_id) : ''}
                            onValueChange={(v) => setFormData({ ...formData, formation_id: Number(v) })}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select training" />
                            </SelectTrigger>
                            <SelectContent>
                                {trainings.map((t) => (
                                    <SelectItem key={t.id} value={String(t.id)}>
                                        {t.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Footer */}
                    <div className="col-span-1 md:col-span-2 mt-6">
                        <div className="flex flex-col md:flex-row items-center justify-between gap-4 border-t pt-4">
                            {/* Left side - Resend Link */}
                            {editedUser?.activation_token != null ?
                                <Button
                                    onClick={() => resendLink(editedUser.id)}
                                    type="button"
                                    className="bg-[#e5e5e5] dark:bg-[#262626] text-[#0a0a0a] dark:text-white cursor-pointer py-1 px-2 w-fit flex gap-2 items-center rounded-lg hover:bg-[#e5e5e5] hover:text-[#0a0a0a]"

                                >
                                    Resend Link
                                </Button>
                                :
                                <Button
                                    onClick={() => resetPassword(editedUser.id)}
                                    type="button"
                                    className="bg-[#e5e5e5] dark:bg-[#262626] text-[#0a0a0a] dark:text-white cursor-pointer py-1 px-2 w-fit flex gap-2 items-center rounded-lg hover:bg-[#e5e5e5] hover:text-[#0a0a0a]"

                                >
                                    Reset Password
                                </Button>
                            }

                            {/* Right side - Action buttons */}
                            <div className="flex gap-2">
                                <Button type="button"
                                    className="bg-[#e5e5e5] dark:bg-[#262626] text-[#0a0a0a] dark:text-white cursor-pointer py-1 px-2 w-fit flex gap-2 items-center rounded-lg hover:bg-[#e5e5e5] hover:text-[#0a0a0a]"
                                    onClick={onClose}>
                                    Cancel
                                </Button>
                                <Button type="submit">Save changes</Button>
                            </div>
                        </div>
                    </div>
                </form>

            </DialogContent>
        </Dialog>
    );
};

export default EditUserModal;

// Reusable roles multiselect (mirrors Add User behavior)
